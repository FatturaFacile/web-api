<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockValuationSnapshot;

interface StockValuationServiceInterface
{
    public function computeItemMovingAverageCost(StockItem $item, float $inboundQuantity, float $inboundUnitCost): float;

    public function generateDailySnapshot(StockItem $item, string $warehouseId): StockValuationSnapshot;

    public function calculateFifoValuation(StockItem $item, float $quantityToDeplete): float;
}
