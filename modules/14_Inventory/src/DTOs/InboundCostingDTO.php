<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

final readonly class InboundCostingDTO
{
    /**
     * @param  string  $quantity  received quantity (numeric string)
     * @param  string  $unitCost  actual unit cost of the inbound (purchase price for receipts)
     * @param  string  $onHandBefore  item on-hand quantity before this inbound
     * @param  string  $sourceType  receipt | adjustment | opening_balance
     */
    public function __construct(
        public string $movementId,
        public string $quantity,
        public string $unitCost,
        public string $onHandBefore,
        public string $sourceType = 'receipt',
        public ?string $warehouseId = null,
        public ?string $lotId = null,
        public ?string $receiptId = null,
    ) {}
}
