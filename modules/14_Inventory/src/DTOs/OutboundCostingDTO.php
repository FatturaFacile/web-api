<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

final readonly class OutboundCostingDTO
{
    /**
     * @param  string  $quantity  issued quantity (numeric string)
     * @param  string  $onHandBefore  item on-hand quantity before this issue
     */
    public function __construct(
        public string $movementId,
        public string $quantity,
        public string $onHandBefore,
        public ?string $lotId = null,
        public ?string $warehouseId = null,
    ) {}
}
