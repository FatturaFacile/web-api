<?php

declare(strict_types=1);

namespace Modules\Inventory\Listeners;

use Modules\Inventory\Events\StockTransferredEvent;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Services\ProductStockLedger;
use Modules\Inventory\Services\StockAllocationPlanner;

/**
 * Stock coming into a bin first settles what was sold beyond the stock of its warehouse (ProductStockLedger), whatever
 * brought it in: a goods receipt, a transfer, an adjustment, a refund at the till. Synchronous on purpose: it runs in
 * the transaction of the incoming movement, so the quant never shows stock that is already owed.
 */
final readonly class SettleStockShortfallsListener
{
    public function __construct(private ProductStockLedger $ledger) {}

    public function handle(StockTransferredEvent $event): void
    {
        if ($event->destinationBinId === null || $event->movementType === ProductStockLedger::COVER_MOVEMENT_TYPE) {
            return;
        }

        $movement = StockMovement::withoutGlobalScopes()->find($event->movementId);
        $item = StockItem::withoutGlobalScopes()->find($event->stockItemId);
        $bin = WarehouseBin::withoutGlobalScopes()->find($event->destinationBinId);

        if ($movement === null || $item === null || $bin === null || $movement->warehouse_id === null) {
            return;
        }

        // Stock in quarantine or in transit is not available yet: it settles shortfalls when it is released.
        $zoneType = WarehouseBin::withoutGlobalScopes()
            ->join('warehouse_racks as r', 'r.id', '=', 'warehouse_bins.warehouse_rack_id')
            ->join('warehouse_aisles as a', 'a.id', '=', 'r.warehouse_aisle_id')
            ->join('warehouse_zones as z', 'z.id', '=', 'a.warehouse_zone_id')
            ->whereKey($bin->id)
            ->value('z.zone_type');
        $lotQuarantined = $movement->lot_id !== null && (bool) StockLot::withoutGlobalScopes()->whereKey($movement->lot_id)->value('is_quarantined');

        if (in_array($zoneType, StockAllocationPlanner::BLOCKED_ZONE_TYPES, true) || $lotQuarantined) {
            return;
        }

        $this->ledger->settleShortfalls($item, $bin, (string) $movement->warehouse_id, $movement->lot_id);
    }
}
