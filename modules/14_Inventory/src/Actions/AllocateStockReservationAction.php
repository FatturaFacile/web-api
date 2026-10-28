<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Modules\Inventory\Contracts\StockReservationServiceInterface;
use Modules\Inventory\DTOs\StockReservationDTO;
use Modules\Inventory\Models\StockReservation;

final readonly class AllocateStockReservationAction
{
    public function __construct(
        private StockReservationServiceInterface $service
    ) {}

    public function execute(StockReservationDTO $dto): StockReservation
    {
        return $this->service->allocate($dto);
    }
}
