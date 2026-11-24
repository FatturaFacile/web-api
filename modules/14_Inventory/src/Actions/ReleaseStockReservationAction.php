<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Modules\Inventory\Contracts\StockReservationServiceInterface;
use Modules\Inventory\Models\StockReservation;

final readonly class ReleaseStockReservationAction
{
    public function __construct(
        private StockReservationServiceInterface $service
    ) {}

    public function execute(StockReservation $reservation): void
    {
        $this->service->release($reservation);
    }
}
