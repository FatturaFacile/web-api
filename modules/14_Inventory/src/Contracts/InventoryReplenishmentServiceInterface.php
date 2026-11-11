<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockReorderRule;

interface InventoryReplenishmentServiceInterface
{
    public function evaluateReorderRule(StockReorderRule $rule): ?string;

    /**
     * Announces StockBelowMinimumEvent when the available stock of the rule drops below its minimum stock level, once per
     * crossing (never again while it stays below); returns the event id, or null when nothing was announced.
     */
    public function evaluateMinimumStock(StockReorderRule $rule): ?string;

    public function computeAbcClassification(string $tenantId): array;

    public function calculateSafetyStock(StockItem $item, int $leadTimeDays, float $serviceLevelFactor = 1.65): float;

    /**
     * Available quantity of the item in the warehouse as a decimal string: on hand minus reserved, outside quarantine
     * and transit zones and quarantined lots. The one availability rule other modules (Procurement) must read.
     */
    public function availableInWarehouse(StockItem $item, string $warehouseId): string;
}
