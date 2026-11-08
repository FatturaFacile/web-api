<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\DeliveryNoteStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SalesReturnStatus;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * Sales returns (RMA), the reverse logistics of the sales cycle.
 *
 * A return refers to the delivery note or the invoice the goods came with, line by line: a line can be returned up to
 * what was delivered (or invoiced) less what other open or completed returns already took back. The price of the line
 * is the price it was sold at. Once a sales manager authorises it, the warehouse receives it: resellable goods of
 * inventory-tracked products go back into stock through the stock service of the inventory module (a customer return
 * movement), damaged goods do not. The return is then credited with one credit note on the original invoice, issued by
 * the invoicing module for the quantities actually received.
 */
final readonly class SalesReturnService
{
    /**
     * Statuses of the returns whose quantities count against what can still be returned.
     *
     * @var list<string>
     */
    private const array ActiveStatuses = ['requested', 'approved', 'received', 'credited'];

    public function __construct(
        private DocumentNumberingService $numbering,
        private DocumentEventRecorder $events,
        private StockService $stock,
        private InvoicingServiceInterface $invoicing,
        private TenantClock $clock,
    ) {}

    /**
     * Records a return request. `delivery_note_id` or `invoice_id` names the source document; each line names the line
     * of that document it returns (`delivery_note_item_id` or `invoice_item_id`).
     *
     * @param  array{delivery_note_id?: ?string, invoice_id?: ?string, warehouse_id?: ?string, date?: ?string, reason: string, notes?: ?string, items: array<int, array{delivery_note_item_id?: ?string, invoice_item_id?: ?string, quantity: float|int|string, condition?: ?string, restock?: ?bool}>}  $data
     *
     * @throws ValidationException when the source is not issued or a quantity exceeds what can be returned
     */
    public function create(array $data, ?string $userId): SalesReturn
    {
        return DB::transaction(function () use ($data, $userId): SalesReturn {
            $note = isset($data['delivery_note_id']) ? DeliveryNote::query()->whereKey($data['delivery_note_id'])->lockForUpdate()->firstOrFail() : null;
            $invoice = isset($data['invoice_id']) ? Invoice::query()->whereKey($data['invoice_id'])->lockForUpdate()->firstOrFail() : null;

            if ($note === null && $invoice === null) {
                throw ValidationException::withMessages(['delivery_note_id' => __('sales.return_source_required')]);
            }

            if ($note !== null && $note->status === DeliveryNoteStatus::Draft->value) {
                throw ValidationException::withMessages(['delivery_note_id' => __('sales.return_note_not_issued')]);
            }

            if ($invoice !== null && ($invoice->status !== InvoiceStatus::Posted->value || in_array($invoice->invoice_type, ['proforma', 'credit_note'], true))) {
                throw ValidationException::withMessages(['invoice_id' => __('sales.return_invoice_not_issued')]);
            }

            if ($note !== null && $invoice !== null && (string) $note->contact_id !== (string) $invoice->contact_id) {
                throw ValidationException::withMessages(['invoice_id' => __('sales.return_documents_other_customer')]);
            }

            $tenantId = (string) ($note->tenant_id ?? $invoice?->tenant_id);
            $date = CarbonImmutable::parse($data['date'] ?? $this->clock->today(Tenant::query()->find($tenantId))->toDateString());
            $lines = $this->resolveLines($data['items'], $note, $invoice);

            $return = SalesReturn::query()->create([
                'contact_id' => (string) ($note->contact_id ?? $invoice?->contact_id),
                'return_number' => $this->numbering->next('sales_return', $date->year, $tenantId)['number'],
                'date' => $date->toDateString(),
                'status' => SalesReturnStatus::Requested->value,
                'sales_order_id' => $note?->sales_order_id,
                'delivery_note_id' => $note?->id,
                'invoice_id' => $invoice?->id,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($lines as $line) {
                $return->items()->create($line);
            }

            $this->events->record($return, 'created');

            return $return->load('items');
        });
    }

    /**
     * Authorises a requested return.
     */
    public function approve(SalesReturn $return, ?string $userId): SalesReturn
    {
        return $this->transition($return, SalesReturnStatus::Approved, ['approved_by' => $userId, 'approved_at' => now()], 'approved');
    }

    /**
     * Refuses a requested return, with the reason told to the customer.
     */
    public function reject(SalesReturn $return, string $reason): SalesReturn
    {
        return $this->transition($return, SalesReturnStatus::Rejected, ['rejection_reason' => $reason], 'rejected');
    }

    /**
     * Cancels a return that was not received yet.
     */
    public function cancel(SalesReturn $return): SalesReturn
    {
        return $this->transition($return, SalesReturnStatus::Cancelled, [], 'cancelled');
    }

    /**
     * Receives an authorised return. Without lines every line is received in full; a line can be received in part, and
     * its condition and restock flag can be corrected on receipt. Resellable lines of tracked products marked for restock
     * go back into the warehouse of the return (the primary warehouse when none is set).
     *
     * @param  array<int, array{id: string, quantity_received: float|int|string, condition?: ?string, restock?: ?bool}>  $lines
     *
     * @throws ValidationException when the return is not authorised or a received quantity exceeds the returned one
     */
    public function receive(SalesReturn $return, array $lines, ?string $warehouseId = null): SalesReturn
    {
        return DB::transaction(function () use ($return, $lines, $warehouseId): SalesReturn {
            $locked = SalesReturn::query()->with('items')->whereKey($return->id)->lockForUpdate()->firstOrFail();
            SalesReturnStatus::assertTransition($locked->status, SalesReturnStatus::Received);

            if ($locked->status === SalesReturnStatus::Received->value) {
                return $locked;
            }

            $byId = collect($lines)->keyBy(static fn (array $line): string => (string) $line['id']);

            foreach ($byId->keys() as $index => $id) {
                if (! $locked->items->contains('id', $id)) {
                    throw ValidationException::withMessages(["items.{$index}.id" => __('sales.return_line_not_of_return')]);
                }
            }

            $warehouse = ($warehouseId ?? $locked->warehouse_id) !== null ? Warehouse::query()->findOrFail($warehouseId ?? $locked->warehouse_id) : $this->stock->defaultWarehouse();

            foreach ($locked->items as $item) {
                $line = $byId->get((string) $item->id);
                $received = $line !== null ? bcadd(DocumentTotalsCalculator::decimal($line['quantity_received']), '0', 4) : bcadd(DocumentTotalsCalculator::decimal($item->quantity), '0', 4);

                if (Decimal::isNegative($received) || Decimal::compare($received, DocumentTotalsCalculator::decimal($item->quantity)) > 0) {
                    throw ValidationException::withMessages(['items' => __('sales.return_received_exceeds', ['line' => $item->description])]);
                }

                $item->update([
                    'quantity_received' => $received,
                    'condition' => $line['condition'] ?? $item->condition,
                    'restock' => array_key_exists('restock', $line ?? []) ? (bool) $line['restock'] : $item->restock,
                ]);
            }

            $restockable = $locked->items->filter(static fn (SalesReturnItem $item): bool => $item->restock
                && $item->condition !== 'damaged'
                && $item->product_id !== null
                && Decimal::isPositive(DocumentTotalsCalculator::decimal($item->quantity_received)));
            $tracked = Product::query()->whereIn('id', $restockable->pluck('product_id')->unique())->where('track_inventory', true)->pluck('cost_price', 'id');

            foreach ($restockable as $item) {
                if ($tracked->has($item->product_id)) {
                    $this->stock->record((string) $item->product_id, 'in', (float) $item->quantity_received, $locked, $warehouse, (float) $tracked[$item->product_id], __('sales.return_stock_note', ['number' => $locked->return_number]));
                }
            }

            $locked->update(['status' => SalesReturnStatus::Received->value, 'received_at' => now(), 'warehouse_id' => $warehouse?->id]);
            $this->events->record($locked, 'received');

            return $locked->refresh()->load('items');
        });
    }

    /**
     * Credits a received return with one credit note on the original invoice, for the received quantities at the price
     * they were sold. The invoice is the one named on the return or, for a return of a delivery note, the one that
     * invoiced its lines.
     *
     * @throws ValidationException when the return is not received, nothing was received or no single invoice is found
     */
    public function credit(SalesReturn $return, ?string $userId): SalesReturn
    {
        return DB::transaction(function () use ($return, $userId): SalesReturn {
            $locked = SalesReturn::query()->with('items')->whereKey($return->id)->lockForUpdate()->firstOrFail();
            SalesReturnStatus::assertTransition($locked->status, SalesReturnStatus::Credited);

            if ($locked->status === SalesReturnStatus::Credited->value) {
                return $locked;
            }

            $received = $locked->items->filter(static fn (SalesReturnItem $item): bool => Decimal::isPositive(DocumentTotalsCalculator::decimal($item->quantity_received)));

            if ($received->isEmpty()) {
                throw ValidationException::withMessages(['items' => __('sales.return_nothing_received')]);
            }

            $invoiceId = $locked->invoice_id ?? $this->invoiceOfLines($received);
            $original = SalesInvoice::query()->findOrFail($invoiceId);

            $creditNote = $this->invoicing->issueCreditNote($original, [
                'date' => $this->clock->today(Tenant::query()->find($locked->tenant_id))->toDateString(),
                'notes' => __('sales.return_credit_note_notes', ['number' => $locked->return_number, 'invoice' => $original->invoice_number]),
                'created_by' => $userId,
            ], $received->map(static fn (SalesReturnItem $item): array => [
                'product_id' => $item->product_id,
                'description' => $item->description,
                'quantity' => (string) $item->quantity_received,
                'unit_price' => (string) $item->unit_price,
                'tax_rate' => (string) $item->tax_rate,
                'discount_percentage' => (string) $item->discount_percentage,
            ])->values()->all());

            $locked->update([
                'status' => SalesReturnStatus::Credited->value,
                'credit_note_id' => (string) $creditNote->id,
                'invoice_id' => (string) $original->id,
                'credited_at' => now(),
            ]);
            $this->events->record($locked, 'credited', ['credit_note_id' => (string) $creditNote->id]);

            return $locked->refresh()->load('items');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(SalesReturn $return, SalesReturnStatus $target, array $attributes, string $event): SalesReturn
    {
        return DB::transaction(function () use ($return, $target, $attributes, $event): SalesReturn {
            $locked = SalesReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            SalesReturnStatus::assertTransition($locked->status, $target);

            if ($locked->status !== $target->value) {
                $locked->update(['status' => $target->value] + $attributes);
                $this->events->record($locked, $event);
            }

            return $locked->load('items');
        });
    }

    /**
     * The lines of the return, priced as they were sold, each within what is still returnable.
     *
     * @param  array<int, array{delivery_note_item_id?: ?string, invoice_item_id?: ?string, quantity: float|int|string, condition?: ?string, restock?: ?bool}>  $items
     * @return list<array<string, mixed>>
     */
    private function resolveLines(array $items, ?DeliveryNote $note, ?Invoice $invoice): array
    {
        $lines = [];
        $requested = [];

        foreach ($items as $index => $item) {
            $quantity = bcadd(DocumentTotalsCalculator::decimal($item['quantity']), '0', 4);

            if (! empty($item['delivery_note_item_id'])) {
                $source = $note !== null ? DeliveryNoteItem::query()->where('delivery_note_id', $note->id)->whereKey($item['delivery_note_item_id'])->first() : null;

                if ($source === null) {
                    throw ValidationException::withMessages(["items.{$index}.delivery_note_item_id" => __('sales.return_line_not_of_source')]);
                }

                $orderLine = $source->sales_order_item_id !== null ? SalesOrderItem::query()->find($source->sales_order_item_id) : null;
                $key = 'dn:'.$source->id;
                $requested[$key] = Decimal::add($requested[$key] ?? '0', $quantity);
                $this->assertReturnable($requested[$key], (string) $source->quantity, 'delivery_note_item_id', (string) $source->id, (string) $source->description);

                $lines[] = [
                    'delivery_note_item_id' => (string) $source->id,
                    'sales_order_item_id' => $source->sales_order_item_id,
                    'product_id' => $source->product_id,
                    'description' => (string) $source->description,
                    'quantity' => $quantity,
                    'unit_price' => bcadd(DocumentTotalsCalculator::decimal($source->unit_price), '0', 4),
                    'tax_rate' => DocumentTotalsCalculator::decimal($source->tax_rate),
                    'discount_percentage' => DocumentTotalsCalculator::decimal($orderLine?->discount_percentage),
                    'condition' => $item['condition'] ?? 'resellable',
                    'restock' => (bool) ($item['restock'] ?? true),
                ];

                continue;
            }

            $source = $invoice !== null && ! empty($item['invoice_item_id']) ? InvoiceItem::query()->where('invoice_id', $invoice->id)->whereKey($item['invoice_item_id'])->first() : null;

            if ($source === null) {
                throw ValidationException::withMessages(["items.{$index}.invoice_item_id" => __('sales.return_line_not_of_source')]);
            }

            $key = 'inv:'.$source->id;
            $requested[$key] = Decimal::add($requested[$key] ?? '0', $quantity);
            $this->assertReturnable($requested[$key], (string) $source->quantity, 'invoice_item_id', (string) $source->id, (string) $source->description);

            $lines[] = [
                'invoice_item_id' => (string) $source->id,
                'delivery_note_item_id' => $source->getAttribute('delivery_note_item_id'),
                'sales_order_item_id' => $source->getAttribute('sales_order_item_id'),
                'product_id' => $source->product_id,
                'description' => (string) $source->description,
                'quantity' => $quantity,
                'unit_price' => bcadd(DocumentTotalsCalculator::decimal($source->unit_price), '0', 4),
                'tax_rate' => DocumentTotalsCalculator::decimal($source->tax_rate),
                'discount_percentage' => DocumentTotalsCalculator::decimal($source->discount_percentage),
                'condition' => $item['condition'] ?? 'resellable',
                'restock' => (bool) ($item['restock'] ?? true),
            ];
        }

        return $lines;
    }

    /**
     * @throws ValidationException when the quantity is not positive or exceeds what other returns left
     */
    private function assertReturnable(string $quantity, string $sourceQuantity, string $column, string $sourceId, string $description): void
    {
        $alreadyReturned = SalesReturnItem::query()
            ->where($column, $sourceId)
            ->whereHas('salesReturn', static fn ($query) => $query->whereIn('status', self::ActiveStatuses))
            ->pluck('quantity')
            ->reduce(static fn (string $sum, mixed $returned): string => Decimal::add($sum, DocumentTotalsCalculator::decimal($returned)), '0');

        if (! Decimal::isPositive($quantity) || Decimal::compare(Decimal::add($alreadyReturned, $quantity), DocumentTotalsCalculator::decimal($sourceQuantity)) > 0) {
            throw ValidationException::withMessages(['items' => __('sales.return_quantity_exceeds', ['line' => $description])]);
        }
    }

    /**
     * The one posted invoice that invoiced the delivery note lines of the return.
     *
     * @param  Collection<int, SalesReturnItem>  $items
     *
     * @throws ValidationException when the lines were not invoiced, or by more than one invoice
     */
    private function invoiceOfLines(Collection $items): string
    {
        $invoiceIds = InvoiceItem::query()
            ->whereIn('delivery_note_item_id', $items->pluck('delivery_note_item_id')->filter()->values())
            ->whereHas('invoice', static fn ($query) => $query->where('status', InvoiceStatus::Posted->value)->whereNotIn('invoice_type', ['proforma', 'credit_note']))
            ->distinct()
            ->pluck('invoice_id');

        if ($invoiceIds->isEmpty()) {
            throw ValidationException::withMessages(['invoice_id' => __('sales.return_not_invoiced')]);
        }

        if ($invoiceIds->count() > 1) {
            throw ValidationException::withMessages(['invoice_id' => __('sales.return_several_invoices')]);
        }

        return (string) $invoiceIds->first();
    }
}
