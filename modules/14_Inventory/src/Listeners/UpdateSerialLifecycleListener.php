<?php

declare(strict_types=1);

namespace Modules\Inventory\Listeners;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Modules\Inventory\Events\SerialAssignedEvent;
use Modules\Inventory\Models\StockSerialNumber;

final readonly class UpdateSerialLifecycleListener implements ShouldQueueAfterCommit
{
    public function handle(SerialAssignedEvent $event): void
    {
        $serial = StockSerialNumber::find($event->serialId);
        if (! $serial) {
            $serial = StockSerialNumber::where('serial_number', $event->serialNumber)->first();
        }

        if (! $serial) {
            return;
        }

        $serial->status = $event->assignedContext;
        $serial->save();
    }
}
