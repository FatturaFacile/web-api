<?php

declare(strict_types=1);

namespace Modules\GreenCare\Domain\Weather;

use Carbon\CarbonInterface;

/**
 * Source of the weather outlook that decides whether a crew can mow or spray on a day.
 */
interface IWeatherForecastProvider
{
    /**
     * Forecast of a day at a place, or null when no outlook is available (provider down and nothing cached): the caller then plans without an alert.
     */
    public function forecast(float $latitude, float $longitude, CarbonInterface $date): ?WeatherForecast;
}
