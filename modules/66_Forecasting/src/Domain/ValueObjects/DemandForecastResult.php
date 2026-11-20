<?php

declare(strict_types=1);

namespace Modules\Forecasting\Domain\ValueObjects;

/**
 * Output of a forecasting engine.
 */
final readonly class DemandForecastResult
{
    /**
     * @param  list<float>  $predictions  one value per forecast period, never negative
     * @param  float  $residualStdDev  standard deviation of the one-step errors, in the unit of the series per period
     * @param  float  $seasonalityIndex  seasonal factor of the first forecast period (1.0 = no seasonality)
     * @param  string  $model  name of the model that won the selection
     */
    public function __construct(
        public array $predictions,
        public float $residualStdDev,
        public float $seasonalityIndex,
        public string $model,
    ) {}
}
