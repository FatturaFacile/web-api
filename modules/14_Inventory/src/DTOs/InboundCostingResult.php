<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Enums\ValuationMethod;
use Modules\Inventory\Models\InventoryCostLayer;

final readonly class InboundCostingResult
{
    public function __construct(
        public ValuationMethod $method,
        public InventoryCostLayer $layer,
        public string $valuationUnitCost,
        public string $inventoryValue,
        public string $purchasePriceVariance,
        public string $movingAverageCost,
    ) {}
}
