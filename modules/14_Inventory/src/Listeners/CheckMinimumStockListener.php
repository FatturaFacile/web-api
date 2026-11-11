<?php

declare(strict_types=1);

namespace Modules\Inventory\Listeners;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Modules\Inventory\Contracts\InventoryReplenishmentServiceInterface;
use Modules\Inventory\Events\StockReservedEvent;
use Modules\Inventory\Events\StockTransferredEvent;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockReorderRule;

/**
 * Looks at the minimum stock level of the rules of the item and warehouse after every movement and every reservation
 * (both change the available stock). Queued after commit, like CheckReorderRulesListener: the stock it reads is the
 * committed one. The crossing logic lives in InventoryReplenishmentService::evaluateMinimumStock().
 */
final readonly class CheckMinimumStockListener implements ShouldQueueAfterCommit
{
    public function __construct(private InventoryReplenishmentServiceInterface $replenishmentService) {}

    public function handleTransfer(StockTransferredEvent $event): void
    {
        $movement = StockMovement::find($event->movementId);

        if ($movement !== null) {
            $this->evaluate($event->stockItemId, (string) $movement->warehouse_id);
        }
    }

    public function handleReservation(StockReservedEvent $event): void
    {
        $this->evaluate($event->stockItemId, $event->warehouseId);
    }

    private function evaluate(string $stockItemId, string $warehouseId): void
    {
        foreach (StockReorderRule::where('stock_item_id', $stockItemId)->where('warehouse_id', $warehouseId)->get() as $rule) {
            $this->replenishmentService->evaluateMinimumStock($rule);
        }
    }
}
