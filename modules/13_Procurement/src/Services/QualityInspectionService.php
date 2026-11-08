<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Services\PurchaseOrderLineStockItemResolver;
use Modules\Inventory\Services\SupplierReceiptStockService;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Models\PurchaseReceiptLine;
use Modules\Procurement\Support\Decimal;

/**
 * Quality inspection of goods received into quarantine. The released quantity moves to the receiving bin of the
 * warehouse through the inventory module; the rejected quantity stays in quarantine until it is returned to the
 * supplier. A line is inspected once.
 */
final readonly class QualityInspectionService
{
    public function __construct(
        private SupplierReceiptStockService $stock,
        private PurchaseOrderLineStockItemResolver $stockItems,
        private ProcurementAuditLedgerService $auditLedger,
    ) {}

    /**
     * Records the outcome of the inspection of receipt lines.
     *
     * @param  list<array{purchase_receipt_line_id: string, quantity_released: numeric-string|float|int, notes?: ?string}>  $results
     *
     * @throws ProcurementRuleViolation when the receipt needs no inspection, a line was already inspected or a quantity is out of range
     */
    public function inspect(PurchaseReceipt $receipt, array $results, string $inspectorUserId): PurchaseReceipt
    {
        return DB::transaction(function () use ($receipt, $results, $inspectorUserId): PurchaseReceipt {
            $locked = PurchaseReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();

            if (! $locked->inspection_required) {
                throw new ProcurementRuleViolation('This goods receipt does not require a quality inspection.', 'inspection');
            }

            $warehouse = $locked->warehouse_id !== null ? Warehouse::query()->find($locked->warehouse_id) : null;

            foreach ($results as $index => $result) {
                $line = PurchaseReceiptLine::query()->whereKey($result['purchase_receipt_line_id'])->where('purchase_receipt_id', $locked->id)->lockForUpdate()->first();

                if ($line === null) {
                    throw new ProcurementRuleViolation('Result '.($index + 1).' names a line of another receipt.', "results.{$index}.purchase_receipt_line_id");
                }

                if ($line->inspection_status !== 'pending') {
                    throw new ProcurementRuleViolation('Result '.($index + 1).': the line was already inspected.', "results.{$index}.purchase_receipt_line_id");
                }

                $inQuarantine = Decimal::sub((string) $line->quantity_accepted, (string) $line->quantity_returned, 4);
                $released = Decimal::of($result['quantity_released'], 4);

                if (Decimal::cmp($released, '0', 4) < 0 || Decimal::cmp($released, $inQuarantine, 4) > 0) {
                    throw new ProcurementRuleViolation('Result '.($index + 1).": between 0 and {$inQuarantine} can be released.", "results.{$index}.quantity_released");
                }

                $this->release($line, $warehouse, $released);

                $status = match (true) {
                    Decimal::cmp($released, $inQuarantine, 4) === 0 => 'passed',
                    Decimal::isPositive($released) => 'partial',
                    default => 'failed',
                };

                $line->update(['quantity_released' => $released, 'inspection_status' => $status, 'inspection_notes' => $result['notes'] ?? null]);
            }

            $pending = PurchaseReceiptLine::query()->where('purchase_receipt_id', $locked->id)->where('inspection_status', 'pending')->exists();
            $statuses = PurchaseReceiptLine::query()->where('purchase_receipt_id', $locked->id)->pluck('inspection_status')->unique()->values()->all();
            $headerStatus = match (true) {
                $pending => 'pending',
                $statuses === ['passed'] => 'passed',
                $statuses === ['failed'] => 'failed',
                default => 'conditional',
            };

            $locked->update(['inspection_status' => $headerStatus, 'inspected_at' => $pending ? null : now(), 'inspected_by' => $inspectorUserId]);
            $this->auditLedger->record(action: 'GOODS_RECEIPT_INSPECTED', model: $locked, payloadBefore: null, payloadAfter: ['inspection_status' => $headerStatus]);

            return $locked->fresh('lines') ?? $locked;
        });
    }

    /**
     * Moves the released quantity of a stocked line from quarantine to the receiving bin.
     */
    private function release(PurchaseReceiptLine $line, ?Warehouse $warehouse, string $released): void
    {
        if (! Decimal::isPositive($released) || $line->warehouse_bin_id === null || $warehouse === null) {
            return;
        }

        $item = $this->stockItems->resolve((string) $line->purchase_order_line_id, (string) $line->tenant_id);

        if ($item === null) {
            return;
        }

        $lotId = $line->lot_number !== null
            ? StockLot::query()->where('stock_item_id', $item->id)->where('lot_number', $line->lot_number)->value('id')
            : null;

        $this->stock->releaseFromQuarantine(
            $item,
            WarehouseBin::query()->findOrFail($line->warehouse_bin_id),
            $this->stock->receivingBin($warehouse),
            $released,
            $lotId !== null ? (string) $lotId : null,
            (string) $line->purchase_receipt_id,
        );
    }
}
