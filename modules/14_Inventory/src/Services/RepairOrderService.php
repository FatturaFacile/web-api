<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Models\Invoice;
use App\Models\Product;
use App\Services\Invoicing\InvoiceService;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\RepairOrder;
use Modules\Inventory\Models\RepairOrderLine;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockSerialNumber;

/**
 * Warranty status of a serial and the repairs opened on it. A repair copies the customer and the purchase value of the
 * serial at intake, and whether the warranty covered that day, so later changes do not rewrite it. The warranty dates
 * are the ones entered on the serial: no period is assumed.
 */
final class RepairOrderService
{
    public function __construct(
        private readonly DoubleEntryMovementServiceInterface $movements,
        private readonly InvoiceService $invoices,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @return array{status: string, days_left: ?int}
     */
    public function warrantyStatus(StockSerialNumber $serial, ?CarbonImmutable $on = null): array
    {
        $on ??= CarbonImmutable::today();

        if ($serial->warranty_end_date === null) {
            return ['status' => 'none', 'days_left' => null];
        }

        $end = CarbonImmutable::parse($serial->warranty_end_date)->startOfDay();

        if ($serial->warranty_start_date !== null && $on->lt(CarbonImmutable::parse($serial->warranty_start_date)->startOfDay())) {
            return ['status' => 'not_started', 'days_left' => null];
        }

        return $on->gt($end)
            ? ['status' => 'expired', 'days_left' => 0]
            : ['status' => 'active', 'days_left' => (int) $on->diffInDays($end)];
    }

    /**
     * @param  array{fault_description: string, contact_id?: ?string}  $data
     */
    public function open(StockSerialNumber $serial, array $data, string $userId): RepairOrder
    {
        $contactId = $data['contact_id'] ?? $serial->contact_id;

        if ($contactId === null) {
            throw new StockRuleViolation('This serial has no customer: name one to open the repair.');
        }

        return DB::transaction(function () use ($serial, $data, $userId, $contactId): RepairOrder {
            $repair = RepairOrder::create([
                'tenant_id' => $serial->tenant_id,
                'repair_number' => 'REP-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
                'stock_serial_number_id' => $serial->id,
                'stock_item_id' => $serial->stock_item_id,
                'contact_id' => $contactId,
                'purchase_value' => $serial->purchase_value,
                'purchase_currency' => $serial->purchase_currency,
                'is_under_warranty' => $this->warrantyStatus($serial)['status'] === 'active',
                'status' => 'received',
                'fault_description' => $data['fault_description'],
                'received_at' => now(),
                'created_by' => $userId,
            ]);

            $serial->update(['status' => 'in_repair']);

            return $repair;
        });
    }

    /**
     * @param  array<string, mixed>  $changes  status and repair notes
     */
    public function advance(RepairOrder $repair, array $changes): RepairOrder
    {
        return DB::transaction(function () use ($repair, $changes): RepairOrder {
            $locked = RepairOrder::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            $status = $changes['status'];

            if (! in_array($status, RepairOrder::TRANSITIONS[$locked->status] ?? [], true)) {
                throw new StockRuleViolation("A repair in status [{$locked->status}] cannot become [{$status}].");
            }

            $locked->update(array_filter([
                'status' => $status,
                'diagnosis' => $changes['diagnosis'] ?? null,
                'resolution' => $changes['resolution'] ?? null,
                'labour_amount' => $changes['labour_amount'] ?? null,
                'delivered_at' => $status === 'delivered' ? now() : null,
            ], static fn (mixed $value): bool => $value !== null));

            if (in_array($status, ['delivered', 'cancelled'], true)) {
                StockSerialNumber::query()->whereKey($locked->stock_serial_number_id)->update(['status' => 'delivered']);
            }

            return $locked;
        });
    }

    /**
     * Invoices a repair outside warranty, once: a labour line for the labour amount and a line for each spare part,
     * through the ordinary sales invoice flow, which numbers, issues and posts it. The parts already left stock when
     * they were used on the repair (one movement per part line), so invoicing issues nothing again; a part line without
     * its movement stops the invoice. A part is priced at the sale price of its product unless the request states one,
     * and every line takes the VAT rate of the request unless the part states its own: no rate is assumed.
     *
     * @param  array{tax_rate: numeric-string|float|int, date?: ?string, currency?: ?string, parts?: list<array{line_id: string, unit_price?: numeric-string|float|int|null, tax_rate?: numeric-string|float|int|null}>}  $data
     * @return array{repair: RepairOrder, invoice: Invoice, created: bool}
     *
     * @throws StockRuleViolation when the repair is under warranty, not finished, has nothing to invoice or a part has no price
     */
    public function invoice(RepairOrder $repair, array $data): array
    {
        return DB::transaction(function () use ($repair, $data): array {
            $locked = RepairOrder::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();

            if ($locked->invoice_id !== null) {
                return ['repair' => $locked, 'invoice' => Invoice::query()->findOrFail($locked->invoice_id), 'created' => false];
            }

            if ($locked->is_under_warranty) {
                throw new StockRuleViolation('A repair under warranty is not invoiced to the customer.');
            }

            if (! in_array($locked->status, ['ready', 'delivered'], true)) {
                throw new StockRuleViolation("A repair in status [{$locked->status}] is not finished and cannot be invoiced.");
            }

            $items = $this->invoiceLines($locked, $data);
            $tenant = $this->tenantContext->getTenant();
            $invoice = $this->invoices->createInvoice([
                'contact_id' => $locked->contact_id,
                'invoice_type' => 'standard',
                'date' => $data['date'] ?? now()->toDateString(),
                'currency' => $data['currency'] ?? ($tenant?->default_currency ?: 'EUR'),
                'jurisdiction' => strtoupper((string) $tenant?->country) === 'CH' ? 'CH' : 'IT',
                'notes' => "Repair {$locked->repair_number}",
                'items' => $items,
            ]);

            $locked->update(['invoice_id' => $invoice->id, 'invoiced_at' => now()]);

            return ['repair' => $locked->load('lines'), 'invoice' => $invoice, 'created' => true];
        });
    }

    /**
     * @param  array{tax_rate: numeric-string|float|int, parts?: list<array{line_id: string, unit_price?: numeric-string|float|int|null, tax_rate?: numeric-string|float|int|null}>}  $data
     * @return list<array<string, mixed>>
     */
    private function invoiceLines(RepairOrder $repair, array $data): array
    {
        $overrides = collect($data['parts'] ?? [])->keyBy('line_id');
        $items = [];

        if (Decimal::isPositive((string) $repair->labour_amount)) {
            $items[] = [
                'description' => trim("Labour, repair {$repair->repair_number}".($repair->resolution ? ": {$repair->resolution}" : '')),
                'quantity' => 1,
                'unit_price' => (string) $repair->labour_amount,
                'tax_rate' => (float) $data['tax_rate'],
            ];
        }

        foreach ($repair->lines()->with('item')->orderBy('created_at')->get() as $line) {
            if ($line->stock_movement_id === null) {
                throw new StockRuleViolation('A spare part of this repair never left stock: it cannot be invoiced.');
            }

            $override = $overrides->get((string) $line->id, []);
            $product = $line->item?->product_id !== null ? Product::query()->find($line->item->product_id) : null;
            $unitPrice = $override['unit_price'] ?? ($product !== null && Decimal::isPositive((string) $product->sale_price) ? (string) $product->sale_price : null);

            if ($unitPrice === null) {
                throw new StockRuleViolation("State the sale price of the spare part [{$line->item?->name}].");
            }

            $items[] = [
                'product_id' => $product?->id,
                'description' => (string) ($line->item?->name ?? 'Spare part'),
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $unitPrice,
                'tax_rate' => (float) ($override['tax_rate'] ?? $data['tax_rate']),
            ];
        }

        if ($items === []) {
            throw new StockRuleViolation('This repair has neither labour nor parts to invoice.');
        }

        return $items;
    }

    /**
     * Takes a spare part out of stock for the repair. The value comes from the movement, so stock and repair agree.
     *
     * @param  array{stock_item_id: string, quantity: mixed, source_bin_id: string, lot_id?: ?string}  $data
     */
    public function addPart(RepairOrder $repair, array $data, string $userId): RepairOrderLine
    {
        return DB::transaction(function () use ($repair, $data, $userId): RepairOrderLine {
            $locked = RepairOrder::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['ready', 'delivered', 'cancelled'], true)) {
                throw new StockRuleViolation("A repair in status [{$locked->status}] takes no more parts.");
            }

            $part = StockItem::query()->findOrFail($data['stock_item_id']);
            $movement = $this->movements->executeTransfer(new StockTransferDTO(
                stockItemId: $part->id,
                sourceBinId: $data['source_bin_id'],
                destinationBinId: null,
                quantity: (float) $data['quantity'],
                movementType: 'repair_consumption',
                lotId: $data['lot_id'] ?? null,
                originDocumentType: 'repair_orders',
                originDocumentId: $locked->id,
                userId: $userId
            ));

            $total = Decimal::round2(Decimal::mul((string) $data['quantity'], (string) $movement->unit_cost));

            $line = RepairOrderLine::create([
                'tenant_id' => $locked->tenant_id,
                'repair_order_id' => $locked->id,
                'stock_item_id' => $part->id,
                'lot_id' => $data['lot_id'] ?? null,
                'quantity' => $data['quantity'],
                'unit_cost' => $movement->unit_cost,
                'line_total' => $total,
                'stock_movement_id' => $movement->id,
            ]);

            $locked->update(['parts_amount' => bcadd((string) $locked->parts_amount, $total, 2)]);

            return $line;
        });
    }
}
