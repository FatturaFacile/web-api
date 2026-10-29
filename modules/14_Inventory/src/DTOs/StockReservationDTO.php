<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

final readonly class StockReservationDTO
{
    public function __construct(
        public string $stockItemId,
        public string $warehouseId,
        public string $reservedForType,
        public string $reservedForId,
        public float $quantity,
        public ?string $expiresAt = null,
        public ?string $userId = null
    ) {}
}
