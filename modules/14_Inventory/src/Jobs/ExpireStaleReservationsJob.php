<?php

declare(strict_types=1);

namespace Modules\Inventory\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Inventory\Contracts\StockReservationServiceInterface;

final class ExpireStaleReservationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->afterCommit();
    }

    public function handle(StockReservationServiceInterface $service): void
    {
        $service->expireStaleReservations();
    }
}
