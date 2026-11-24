<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockValuationSnapshot;

interface StockValuationServiceInterface
{
    public function computeItemMovingAverageCost(StockItem $item, float $inboundQuantity, float $inboundUnitCost): float;

    public function generateDailySnapshot(StockItem $item, string $warehouseId): StockValuationSnapshot;

    /**
     * Captures a valuation snapshot for an item/warehouse. $method accepts the new values
     * (fifo|weighted_average|standard_cost) and legacy ones (FIFO|AVCO|STANDARD); null = effective method.
     */
    public function capturePeriodicSnapshot(StockItem $item, string $warehouseId, ?string $method = null): StockValuationSnapshot;

    public function calculateFifoValuation(StockItem $item, float $quantityToDeplete): float;

    /**
     * Value (2 decimals) of the item's on-hand stock under $method (null = effective method).
     */
    public function valueItem(StockItem $item, ?string $method = null): string;
}
