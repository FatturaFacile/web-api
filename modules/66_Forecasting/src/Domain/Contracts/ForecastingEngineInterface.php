<?php

declare(strict_types=1);

namespace Modules\Forecasting\Domain\Contracts;

use Modules\Forecasting\Domain\ValueObjects\DemandForecastResult;

/**
 * Time series forecasting behind the demand module. The default engine is pure PHP; a Prophet, SARIMAX or LightGBM
 * microservice can replace it by binding another implementation.
 */
interface ForecastingEngineInterface
{
    /**
     * @param  list<float>  $series  chronological, one value per period (the latest last)
     * @param  int  $seasonLength  periods per season (12 for monthly data with a yearly cycle)
     * @param  int  $periodsAhead  how many future periods to forecast (at least 1)
     */
    public function forecast(array $series, int $seasonLength, int $periodsAhead = 1): DemandForecastResult;
}
