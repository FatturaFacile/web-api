<?php

declare(strict_types=1);

namespace Modules\GreenCare\Domain\Weather;

/**
 * Daily outlook of a place.
 */
final readonly class WeatherForecast
{
    public function __construct(
        public float $precipitationMm,
        public float $temperatureMin,
        public float $temperatureMax,
        public float $windKmh,
        public string $source,
        public string $fetchedAt,
    ) {}

    /**
     * @return array{precipitation_mm: float, temp_min: float, temp_max: float, wind_kmh: float, source: string, fetched_at: string}
     */
    public function toArray(): array
    {
        return [
            'precipitation_mm' => $this->precipitationMm,
            'temp_min' => $this->temperatureMin,
            'temp_max' => $this->temperatureMax,
            'wind_kmh' => $this->windKmh,
            'source' => $this->source,
            'fetched_at' => $this->fetchedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (float) ($data['precipitation_mm'] ?? 0),
            (float) ($data['temp_min'] ?? 0),
            (float) ($data['temp_max'] ?? 0),
            (float) ($data['wind_kmh'] ?? 0),
            (string) ($data['source'] ?? 'unknown'),
            (string) ($data['fetched_at'] ?? ''),
        );
    }
}
