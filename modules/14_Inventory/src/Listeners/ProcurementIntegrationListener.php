<?php

declare(strict_types=1);

namespace Modules\Inventory\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\Contracts\StockReservationServiceInterface;
use Modules\Inventory\DTOs\StockReservationDTO;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Procurement\Events\InventoryAdjustmentRequestEvent;
use Modules\Procurement\Events\InventoryReceiptRequestedEvent;
use Modules\Procurement\Events\InventoryReservationRequestedEvent;
use Modules\Procurement\Events\SupplierReturnRequestedEvent;

final readonly class ProcurementIntegrationListener implements ShouldQueue
{
    public function __construct(
        private DoubleEntryMovementServiceInterface $movementService,
        private StockReservationServiceInterface $reservationService
    ) {}

    public function handleInventoryReceipt(InventoryReceiptRequestedEvent $event): void
    {
        $warehouse = Warehouse::find($event->warehouseId) ?? Warehouse::first();
        if (! $warehouse) {
            return;
        }

        $inboundBin = WarehouseBin::whereHas('rack.aisle.zone', function ($query) use ($warehouse) {
            $query->where('warehouse_id', $warehouse->id);
        })->first() ?? WarehouseBin::first();

        if (! $inboundBin) {
            return;
        }

        foreach ($event->items as $item) {
            $stockItem = null;
            if (! empty($item['product_id'])) {
                $stockItem = StockItem::find($item['product_id']);
            }
            if (! $stockItem && ! empty($item['purchase_order_line_id'])) {
                $poLine = DB::table('purchase_order_lines')->where('id', $item['purchase_order_line_id'])->first();
                if ($poLine && isset($poLine->product_id)) {
                    $stockItem = StockItem::find($poLine->product_id);
                }
            }
            if (! $stockItem) {
                $stockItem = StockItem::first();
            }

            if (! $stockItem || ($item['quantity'] ?? 0) <= 0) {
                continue;
            }

            $dto = new StockTransferDTO(
                stockItemId: (string) $stockItem->id,
                sourceBinId: null,
                destinationBinId: (string) $inboundBin->id,
                quantity: (float) $item['quantity'],
                movementType: 'receipt',
                originDocumentType: 'purchase_receipt',
                originDocumentId: $event->receiptId,
                userId: DB::table('tenant_user')->where('tenant_id', $event->tenantId)->value('user_id')
            );

            $this->movementService->executeTransfer($dto);
        }
    }

    public function handleInventoryReservation(InventoryReservationRequestedEvent $event): void
    {
        $stockItem = StockItem::find($event->stockItemId);
        if (! $stockItem) {
            return;
        }

        $dto = new StockReservationDTO(
            stockItemId: $event->stockItemId,
            warehouseId: $event->warehouseId,
            quantity: $event->quantity,
            reservedForType: 'purchase_order',
            reservedForId: $event->purchaseOrderId
        );

        $this->reservationService->allocate($dto);
    }

    public function handleSupplierReturn(SupplierReturnRequestedEvent $event): void
    {
        foreach ($event->lines as $line) {
            $stockItem = StockItem::find($line['product_id']);
            if (! $stockItem) {
                continue;
            }

            $warehouseId = $line['warehouse_id'] ?? null;
            $bin = null;
            if ($warehouseId) {
                $bin = WarehouseBin::whereHas('rack.aisle.zone', function ($query) use ($warehouseId) {
                    $query->where('warehouse_id', $warehouseId);
                })->first();
            } else {
                $bin = WarehouseBin::first();
            }

            if (! $bin) {
                continue;
            }

            $dto = new StockTransferDTO(
                stockItemId: (string) $stockItem->id,
                sourceBinId: (string) $bin->id,
                destinationBinId: null, // Out of warehouse (Supplier return)
                quantity: (float) $line['quantity'],
                movementType: 'dispatch',
                originDocumentType: 'purchase_return',
                originDocumentId: $event->returnId
            );

            $this->movementService->executeTransfer($dto);
        }
    }

    public function handleInventoryAdjustment(InventoryAdjustmentRequestEvent $event): void
    {
        $stockItem = StockItem::find($event->stockItemId);
        if (! $stockItem) {
            return;
        }

        $bin = WarehouseBin::whereHas('rack.aisle.zone', function ($query) use ($event) {
            $query->where('warehouse_id', $event->warehouseId);
        })->first() ?? WarehouseBin::first();

        if (! $bin) {
            return;
        }

        if ($event->quantityDelta > 0) {
            $dto = new StockTransferDTO(
                stockItemId: (string) $stockItem->id,
                sourceBinId: null,
                destinationBinId: (string) $bin->id,
                quantity: (float) $event->quantityDelta,
                movementType: 'adjustment',
                originDocumentType: 'procurement_adjustment',
                originDocumentId: $event->purchaseOrderId
            );
        } else {
            $dto = new StockTransferDTO(
                stockItemId: (string) $stockItem->id,
                sourceBinId: (string) $bin->id,
                destinationBinId: null,
                quantity: abs((float) $event->quantityDelta),
                movementType: 'adjustment',
                originDocumentType: 'procurement_adjustment',
                originDocumentId: $event->purchaseOrderId
            );
        }

        $this->movementService->executeTransfer($dto);
    }
}
