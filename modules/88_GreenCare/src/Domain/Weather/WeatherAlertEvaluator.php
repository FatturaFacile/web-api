<?php

declare(strict_types=1);

namespace Modules\GreenCare\Domain\Weather;

use Modules\GreenCare\Domain\Enums\ServiceType;
use Modules\GreenCare\Domain\Enums\WeatherAlertStatus;

/**
 * Turns a forecast into the alert of a route and tells which activities the alert stops.
 */
final class WeatherAlertEvaluator
{
    /**
     * Frost wins over rain: a frozen lawn is neither mown nor sprayed whatever the rain.
     */
    public function evaluate(?WeatherForecast $forecast): WeatherAlertStatus
    {
        if ($forecast === null) {
            return WeatherAlertStatus::Clear;
        }

        if ($forecast->temperatureMin <= (float) config('green_care.weather.frost_threshold_celsius', 0)) {
            return WeatherAlertStatus::FrostAlert;
        }

        if ($forecast->precipitationMm >= (float) config('green_care.weather.rain_threshold_mm', 5)
            || $forecast->windKmh >= (float) config('green_care.weather.wind_threshold_kmh', 40)) {
            return WeatherAlertStatus::RainWarning;
        }

        return WeatherAlertStatus::Clear;
    }

    /**
     * Outdoor work that the alert makes impossible or useless: mowing and spraying (also fertilising and irrigation start-up in the rain).
     *
     * @param  list<string>  $activities
     */
    public function blocksActivities(WeatherAlertStatus $status, array $activities): bool
    {
        $blocked = match ($status) {
            WeatherAlertStatus::FrostAlert => [ServiceType::Mowing->value, ServiceType::Phytosanitary->value, ServiceType::IrrigationStartup->value],
            WeatherAlertStatus::RainWarning => [ServiceType::Mowing->value, ServiceType::Phytosanitary->value, ServiceType::Fertilization->value],
            default => [],
        };

        return array_intersect($activities, $blocked) !== [];
    }
}
