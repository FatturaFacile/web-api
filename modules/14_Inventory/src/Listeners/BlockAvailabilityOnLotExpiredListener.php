<?php

declare(strict_types=1);

namespace Modules\Inventory\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Inventory\Events\LotExpiredEvent;
use Modules\Inventory\Models\StockLot;

final readonly class BlockAvailabilityOnLotExpiredListener implements ShouldQueue
{
    public function handle(LotExpiredEvent $event): void
    {
        $lot = StockLot::find($event->lotId);
        if (! $lot) {
            return;
        }

        $lot->is_quarantined = true;
        $lot->save();

        // Quant availability: quants belonging to this lot are quarantined
        foreach ($lot->quants as $quant) {
            $quant->quantity_reserved = $quant->quantity_on_hand; // Block from available stock
            $quant->save();
        }
    }
}
