<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\DTOs\StockReservationDTO;
use Modules\Inventory\Models\StockReservation;

interface StockReservationServiceInterface
{
    public function allocate(StockReservationDTO $dto): StockReservation;

    public function release(StockReservation $reservation): void;

    public function expireStaleReservations(): int;
}
