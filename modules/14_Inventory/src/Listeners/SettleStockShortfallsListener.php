<?php

declare(strict_types=1);

namespace Modules\Inventory\Listeners;

use Modules\Inventory\Events\StockTransferredEvent;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Services\ProductStockLedger;

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

        $this->ledger->settleShortfalls($item, $bin, (string) $movement->warehouse_id, $movement->lot_id);
    }
}
