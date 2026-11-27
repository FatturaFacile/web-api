<?php

declare(strict_types=1);

namespace Modules\GreenCare\Infrastructure\Weather;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\GreenCare\Domain\Weather\IWeatherForecastProvider;
use Modules\GreenCare\Domain\Weather\WeatherForecast;
use Throwable;

/**
 * Open-Meteo daily forecast (no API key, coverage of Switzerland and Italy). A forecast is cached by place (about 1 km) and day; when the
 * service does not answer, the last forecast kept in the stale cache is used, and without one the answer is null (no alert is raised).
 */
final class OpenMeteoForecastProvider implements IWeatherForecastProvider
{
    public function forecast(float $latitude, float $longitude, CarbonInterface $date): ?WeatherForecast
    {
        $key = sprintf('green-care:weather:%.2f:%.2f:%s', $latitude, $longitude, $date->format('Y-m-d'));
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return WeatherForecast::fromArray($cached);
        }

        $fresh = $this->fetch($latitude, $longitude, $date);

        if ($fresh !== null) {
            Cache::put($key, $fresh->toArray(), now()->addMinutes((int) config('green_care.weather.cache_minutes', 180)));
            Cache::put($key.':stale', $fresh->toArray(), now()->addHours((int) config('green_care.weather.stale_hours', 168)));

            return $fresh;
        }

        $stale = Cache::get($key.':stale');

        return is_array($stale) ? WeatherForecast::fromArray($stale) : null;
    }

    private function fetch(float $latitude, float $longitude, CarbonInterface $date): ?WeatherForecast
    {
        try {
            $response = Http::timeout((int) config('green_care.weather.timeout_seconds', 6))
                ->acceptJson()
                ->get((string) config('green_care.weather.provider_url'), [
                    'latitude' => round($latitude, 4),
                    'longitude' => round($longitude, 4),
                    'daily' => 'precipitation_sum,temperature_2m_min,temperature_2m_max,wind_speed_10m_max',
                    'timezone' => 'auto',
                    'start_date' => $date->format('Y-m-d'),
                    'end_date' => $date->format('Y-m-d'),
                ]);

            if (! $response->successful()) {
                return null;
            }

            $daily = $response->json('daily');

            if (! is_array($daily) || ! isset($daily['precipitation_sum'][0], $daily['temperature_2m_min'][0], $daily['temperature_2m_max'][0])) {
                return null;
            }

            return new WeatherForecast(
                precipitationMm: (float) $daily['precipitation_sum'][0],
                temperatureMin: (float) $daily['temperature_2m_min'][0],
                temperatureMax: (float) $daily['temperature_2m_max'][0],
                windKmh: (float) ($daily['wind_speed_10m_max'][0] ?? 0),
                source: 'open-meteo',
                fetchedAt: now()->toIso8601String(),
            );
        } catch (Throwable) {
            return null;
        }
    }
}
