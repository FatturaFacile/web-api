<?php

declare(strict_types=1);

namespace Modules\Inventory\Listeners;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Services\PurchaseOrderLineStockItemResolver;
use Modules\Procurement\Events\GoodsReceivedEvent;

/**
 * Books the accepted quantities of a goods receipt into the warehouse of the receipt. The stock item of each line comes
 * from its supplier catalog entry; a line without one moves nothing and is logged.
 */
final readonly class ProcurementGoodsReceivedListener implements ShouldQueueAfterCommit
{
    public function __construct(
        private DoubleEntryMovementServiceInterface $movementService,
        private PurchaseOrderLineStockItemResolver $stockItems,
    ) {}

    public function handle(GoodsReceivedEvent $event): void
    {
        $warehouse = $this->warehouseOf($event);

        if ($warehouse === null) {
            Log::warning('Goods receipt without a warehouse: no stock was moved.', ['receipt_id' => $event->receiptId]);

            return;
        }

        $receivingBin = WarehouseBin::withoutGlobalScopes()
            ->where('tenant_id', $event->tenantId)
            ->whereHas('rack.aisle.zone', static fn ($query) => $query->withoutGlobalScopes()->where('warehouse_id', $warehouse->id))
            ->orderBy('code')
            ->first();

        if ($receivingBin === null) {
            Log::warning('Goods receipt warehouse without a bin: no stock was moved.', ['receipt_id' => $event->receiptId, 'warehouse_id' => $warehouse->id]);

            return;
        }

        foreach ($event->receivedLines as $line) {
            $acceptedQty = (float) ($line['quantity_accepted'] ?? $line['quantity_received'] ?? 0);

            if ($acceptedQty <= 0) {
                continue;
            }

            $item = $this->stockItems->resolve((string) ($line['purchase_order_line_id'] ?? ''), $event->tenantId);

            if ($item === null) {
                Log::warning('Goods receipt line without a stock item: no stock was moved.', ['purchase_order_line_id' => $line['purchase_order_line_id'] ?? null]);

                continue;
            }

            $lotId = null;

            if (! empty($line['lot_number'])) {
                $lot = StockLot::withoutGlobalScopes()->firstOrCreate(
                    ['tenant_id' => $event->tenantId, 'stock_item_id' => $item->id, 'lot_number' => $line['lot_number']],
                    ['company_id' => $item->company_id, 'received_at' => now(), 'initial_quantity' => $acceptedQty, 'current_quantity' => $acceptedQty, 'status' => 'active'],
                );
                $lotId = (string) $lot->id;
            }

            $this->movementService->executeTransfer(new StockTransferDTO(
                stockItemId: (string) $item->id,
                sourceBinId: null,
                destinationBinId: (string) $receivingBin->id,
                quantity: $acceptedQty,
                movementType: 'receipt',
                lotId: $lotId,
                originDocumentType: 'purchase_receipt',
                originDocumentId: $event->receiptId,
                customUnitCost: isset($line['unit_cost']) ? (float) $line['unit_cost'] : null,
            ));
        }
    }

    /**
     * The warehouse the receipt names, else the first active warehouse of the company.
     */
    private function warehouseOf(GoodsReceivedEvent $event): ?Warehouse
    {
        $warehouseId = DB::table('purchase_receipts')->where('id', $event->receiptId)->where('tenant_id', $event->tenantId)->value('warehouse_id');
        $warehouses = Warehouse::withoutGlobalScopes()->where('tenant_id', $event->tenantId);

        if (is_string($warehouseId) && $warehouseId !== '') {
            return (clone $warehouses)->whereKey($warehouseId)->first();
        }

        return $warehouses->where('company_id', $event->companyId)->where('is_active', true)->orderBy('created_at')->first();
    }
}
