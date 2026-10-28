<?php

declare(strict_types=1);

namespace Modules\Inventory\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Inventory\Contracts\InventoryReplenishmentServiceInterface;
use Modules\Inventory\Events\StockTransferredEvent;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockReorderRule;

final readonly class CheckReorderRulesListener implements ShouldQueue
{
    public function __construct(
        private InventoryReplenishmentServiceInterface $replenishmentService
    ) {}

    public function handle(StockTransferredEvent $event): void
    {
        $movement = StockMovement::find($event->movementId);
        if (! $movement) {
            return;
        }

        $rules = StockReorderRule::where('stock_item_id', $event->stockItemId)
            ->where('warehouse_id', $movement->warehouse_id)
            ->get();

        foreach ($rules as $rule) {
            $this->replenishmentService->triggerReorderIfThresholdReached($rule);
        }
    }
}
