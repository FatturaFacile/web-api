<?php

declare(strict_types=1);

namespace Modules\Returns\Services;

use App\Models\DeliveryNoteItem;
use App\Models\InvoiceItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Returns\Models\CustomerReturn;
use Modules\Returns\Models\CustomerReturnLine;
use Modules\Returns\Support\Decimal;
use Modules\Returns\Support\ReturnsError;

/**
 * Manages Mod 232 Customer Returns (Reverse Logistics): self-service B2B/B2C & backoffice RMA requests,
 * invoice/DDT linkage, restocking fees, RMA barcode & shipping labels, approval, and warehouse receipt.
 */
final readonly class CustomerReturnService
{
    public function __construct(
        private ReturnsNumbering $numbering,
        private ReturnEligibilityService $eligibility,
        private ReturnsSettingsService $settings,
        private ReturnsStockBooker $stock,
        private ReturnsLedger $ledger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data, ?string $userId, bool $mayOverrideWindow): CustomerReturn
    {
        return DB::transaction(function () use ($tenantId, $data, $userId, $mayOverrideWindow): CustomerReturn {
            $this->settings->assertActiveReason($tenantId, (string) $data['reason_code']);
            $eval = $this->eligibility->evaluate($tenantId, $data);

            $overrideWindow = (bool) ($data['override_window'] ?? false);
            if (! $eval['eligible']) {
                if (! $overrideWindow) {
                    throw ReturnsError::on('requested_on', 'rma.window_expired', ['days' => $eval['return_window_days']]);
                }
                if (! $mayOverrideWindow) {
                    throw ReturnsError::on('override_window', 'rma.override_not_allowed');
                }
            }

            $rmaNumber = $this->numbering->next(CustomerReturn::class, 'rma_number', 'CRMA', $tenantId);
            $barcode = $this->eligibility->generateBarcode($rmaNumber);
            $carrier = (string) ($data['carrier'] ?? 'DHL Express Reverse');
            $tracking = $data['return_tracking_number'] ?? ('TRK-'.$rmaNumber);

            $return = CustomerReturn::create([
                'tenant_id' => $tenantId,
                'rma_number' => $rmaNumber,
                'rma_barcode' => $barcode,
                'return_tracking_number' => $tracking,
                'carrier' => $carrier,
                'contact_id' => $data['contact_id'],
                'invoice_id' => $data['invoice_id'] ?? null,
                'delivery_note_id' => $data['delivery_note_id'] ?? null,
                'sales_order_id' => $data['sales_order_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'reason_code' => $data['reason_code'],
                'channel' => $data['channel'] ?? CustomerReturn::ChannelBackoffice,
                'status' => CustomerReturn::StatusRequested,
                'requested_on' => $data['requested_on'] ?? now()->toDateString(),
                'within_return_window' => $eval['within_return_window'],
                'within_warranty_window' => $eval['within_warranty_window'],
                'window_overridden' => ! $eval['eligible'] && $overrideWindow,
                'days_since_purchase' => $eval['days_since_purchase'],
                'restocking_fee_applicable' => $eval['restocking_fee_applicable'],
                'restocking_fee_percent' => $eval['restocking_fee_percent'],
                'restocking_fee_amount' => $eval['restocking_fee_amount'],
                'gross_return_amount' => $eval['gross_return_amount'],
                'net_refundable_amount' => $eval['net_refundable_amount'],
                'photo_urls' => $data['photo_urls'] ?? [],
                'customer_notes' => $data['customer_notes'] ?? null,
                'internal_notes' => $data['internal_notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($data['lines'] as $index => $lineInput) {
                $evaluated = $eval['lines'][$index] ?? null;
                $productId = (string) ($evaluated['product_id'] ?? $lineInput['product_id'] ?? '');

                if ($productId === '' && ! empty($lineInput['invoice_item_id'])) {
                    $productId = (string) InvoiceItem::query()->whereKey($lineInput['invoice_item_id'])->value('product_id');
                }
                if ($productId === '' && ! empty($lineInput['delivery_note_item_id'])) {
                    $productId = (string) DeliveryNoteItem::query()->whereKey($lineInput['delivery_note_item_id'])->value('product_id');
                }

                $product = $productId !== '' ? Product::withTrashed()->where('tenant_id', $tenantId)->whereKey($productId)->first() : null;
                if ($product === null) {
                    throw ReturnsError::on("lines.{$index}.product_id", 'rma.line_not_of_source');
                }

                CustomerReturnLine::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'customer_return_id' => $return->id,
                    'invoice_item_id' => $lineInput['invoice_item_id'] ?? null,
                    'delivery_note_item_id' => $lineInput['delivery_note_item_id'] ?? null,
                    'sales_order_item_id' => $lineInput['sales_order_item_id'] ?? null,
                    'product_id' => (string) $product->id,
                    'description' => (string) ($evaluated['description'] ?? $lineInput['description'] ?? $product->name),
                    'quantity' => Decimal::round($lineInput['quantity'], 4),
                    'unit_price' => (string) ($evaluated['unit_price'] ?? Decimal::round($product->sale_price ?? '0', 4)),
                    'tax_rate' => (string) ($evaluated['tax_rate'] ?? Decimal::round($product->tax_rate ?? '22.00', 2)),
                    'serial_number' => $lineInput['serial_number'] ?? null,
                    'lot_number' => $lineInput['lot_number'] ?? null,
                    'reason_code' => $lineInput['reason_code'] ?? $return->reason_code,
                    'photo_urls' => $lineInput['photo_urls'] ?? [],
                ]);
            }

            $this->ledger->record('returns.customer_return_requested', $return, [
                'channel' => $return->channel,
                'restocking_fee_amount' => $return->restocking_fee_amount,
            ]);

            return $return->load(['lines', 'contact', 'invoice', 'deliveryNote']);
        });
    }

    public function approve(CustomerReturn $return, ?string $userId, ?string $warehouseId): CustomerReturn
    {
        return DB::transaction(function () use ($return, $userId, $warehouseId): CustomerReturn {
            $locked = CustomerReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== CustomerReturn::StatusRequested) {
                throw ReturnsError::on('status', 'transition', ['from' => $locked->status, 'to' => CustomerReturn::StatusApproved]);
            }

            $warehouse = $warehouseId !== null
                ? $this->stock->warehouse((string) $locked->tenant_id, $warehouseId)
                : null;

            $locked->update([
                'status' => CustomerReturn::StatusApproved,
                'approved_at' => now(),
                'approved_by' => $userId,
                'warehouse_id' => $warehouse?->id ?? $locked->warehouse_id,
            ]);

            $this->ledger->record('returns.customer_return_approved', $locked);

            return $locked->refresh()->load(['lines', 'contact', 'invoice', 'deliveryNote']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function receive(CustomerReturn $return, array $data): CustomerReturn
    {
        return DB::transaction(function () use ($return, $data): CustomerReturn {
            $locked = CustomerReturn::query()->with('lines')->whereKey($return->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [CustomerReturn::StatusRequested, CustomerReturn::StatusApproved], true)) {
                throw ReturnsError::on('status', 'transition', ['from' => $locked->status, 'to' => CustomerReturn::StatusReceived]);
            }

            $warehouse = $this->stock->warehouse((string) $locked->tenant_id, $data['warehouse_id'] ?? $locked->warehouse_id);
            $byId = collect($data['lines'] ?? [])->keyBy(static fn (array $row): string => (string) $row['id']);

            foreach ($locked->lines as $line) {
                $qtyReceived = Decimal::round(
                    $byId->has((string) $line->id) ? $byId->get((string) $line->id)['quantity_received'] : $line->quantity,
                    4
                );
                if (Decimal::compare($qtyReceived, '0') < 0 || Decimal::compare($qtyReceived, $line->quantity) > 0) {
                    throw ReturnsError::on('lines', 'rma.received_exceeds', ['line' => $line->description]);
                }
                $line->update(['quantity_received' => $qtyReceived]);
            }

            $locked->update([
                'status' => CustomerReturn::StatusReceived,
                'received_at' => now(),
                'warehouse_id' => $warehouse->id,
            ]);

            $this->ledger->record('returns.customer_return_received', $locked);

            return $locked->refresh()->load(['lines', 'contact', 'invoice', 'deliveryNote']);
        });
    }
}
