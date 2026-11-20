<?php

declare(strict_types=1);

namespace Modules\Forecasting\Application\Commands;

use Modules\Forecasting\Domain\Services\NormalDistribution;

/**
 * Forecast the demand of the products over the next months and size their safety stock and reorder point.
 */
final readonly class GenerateSkuDemandForecastCommand
{
    public function __construct(
        public string $tenantId,
        public ?string $productId = null,
        public ?string $warehouseId = null,
        public int $periodsAhead = 1,
        public float $serviceLevelZScore = NormalDistribution::Z95,
        public int $historyMonths = 24,
    ) {}
}
