<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * A WGS84 coordinate with the foraging radius of the bees living there.
 */
final readonly class GeoPoint
{
    private const float EarthRadiusM = 6371008.8;

    public function __construct(public float $latitude, public float $longitude, public int $radiusM = 3000)
    {
        if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
            throw new InvalidArgumentException('Coordinates out of range.');
        }

        if ($radiusM < 0) {
            throw new InvalidArgumentException('The foraging radius cannot be negative.');
        }
    }

    /**
     * Great-circle distance (haversine) in metres.
     */
    public function distanceTo(self $other): float
    {
        $lat1 = deg2rad($this->latitude);
        $lat2 = deg2rad($other->latitude);
        $deltaLat = $lat2 - $lat1;
        $deltaLon = deg2rad($other->longitude - $this->longitude);
        $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;

        return 2 * self::EarthRadiusM * asin(min(1.0, sqrt($a)));
    }

    /**
     * Whether the two foraging circles overlap (they share flora and, above all, varroa pressure).
     */
    public function overlaps(self $other): bool
    {
        return $this->distanceTo($other) < ($this->radiusM + $other->radiusM);
    }

    /**
     * Overlap depth as a percentage of the smaller circle's diameter (0 when apart, 100 when concentric).
     */
    public function overlapPercent(self $other): float
    {
        $smaller = min($this->radiusM, $other->radiusM);

        if ($smaller === 0) {
            return 0.0;
        }

        $depth = ($this->radiusM + $other->radiusM) - $this->distanceTo($other);

        return round(max(0.0, min(100.0, $depth / (2 * $smaller) * 100)), 1);
    }
}
