<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\ValueObjects;

use Modules\PassengerTransport\Domain\Enums\BorderCrossing;

/**
 * Kilometres of a trip by territory, the crossing used and the estimated driving time.
 */
final readonly class RouteSplit
{
    public function __construct(
        public string $totalKm,
        public string $itKm,
        public string $chKm,
        public string $otherEuKm,
        public BorderCrossing $crossing,
        public int $durationMinutes,
        public string $source,
    ) {}

    /**
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        return [
            'total_distance_km' => $this->totalKm,
            'it_territory_km' => $this->itKm,
            'ch_territory_km' => $this->chKm,
            'other_eu_territory_km' => $this->otherEuKm,
            'border_crossing_point' => $this->crossing->value,
            'estimated_duration_minutes' => $this->durationMinutes,
            'routing_source' => $this->source,
        ];
    }
}
