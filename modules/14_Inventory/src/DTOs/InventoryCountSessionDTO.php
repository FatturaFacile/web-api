<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

final readonly class InventoryCountSessionDTO
{
    /**
     * @param  array<int, string>  $binIds
     */
    public function __construct(
        public string $warehouseId,
        public string $countType,
        public string $scheduledDate,
        public string $userId,
        public array $binIds = [],
        public ?string $sessionNumber = null
    ) {}
}
