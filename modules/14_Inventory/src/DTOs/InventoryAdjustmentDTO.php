<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

final readonly class InventoryAdjustmentDTO
{
    /**
     * @param  array<int, array{stock_item_id: string, warehouse_bin_id: string, quantity_delta: float, unit_cost: float, lot_id?: ?string}>  $lines
     */
    public function __construct(
        public string $warehouseId,
        public string $reason,
        public array $lines,
        public string $userId,
        public ?string $adjustmentNumber = null,
        public ?string $notes = null
    ) {}
}
