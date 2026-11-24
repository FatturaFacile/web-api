<?php

declare(strict_types=1);

namespace Modules\Forecasting\Application\Commands;

/**
 * Write the recommended safety stock and reorder point of forecasts to the warehouse (Mod 051) and planning (Mod 062) data.
 */
final readonly class ApplyAiRecommendedReorderRulesCommand
{
    /**
     * @param  list<string>  $forecastIds  forecasts to apply
     * @param  list<string>  $productIds  products whose latest forecast of every warehouse is applied
     */
    public function __construct(
        public string $tenantId,
        public array $forecastIds = [],
        public array $productIds = [],
    ) {}
}
