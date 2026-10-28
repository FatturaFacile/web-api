<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockReorderRule;

interface InventoryReplenishmentServiceInterface
{
    public function evaluateReorderRule(StockReorderRule $rule): ?string;

    public function computeAbcClassification(string $tenantId): array;

    public function calculateSafetyStock(StockItem $item, int $leadTimeDays, float $serviceLevelFactor = 1.65): float;
}
