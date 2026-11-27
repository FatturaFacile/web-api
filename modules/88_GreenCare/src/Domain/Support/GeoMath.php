<?php

declare(strict_types=1);

namespace Modules\GreenCare\Domain\Support;

/**
 * Geographic helpers of the route planner.
 */
final class GeoMath
{
    private const float EARTH_RADIUS_KM = 6371.0088;

    /**
     * Great-circle distance (Haversine) between two coordinates, in kilometres.
     */
    public static function haversineKm(float $latA, float $lngA, float $latB, float $lngB): float
    {
        $dLat = deg2rad($latB - $latA);
        $dLng = deg2rad($lngB - $lngA);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }

    /**
     * Mean point of a set of coordinates.
     *
     * @param  list<array{0: float, 1: float}>  $points  [lat, lng]
     * @return array{0: float, 1: float}|null
     */
    public static function centroid(array $points): ?array
    {
        if ($points === []) {
            return null;
        }

        return [array_sum(array_column($points, 0)) / count($points), array_sum(array_column($points, 1)) / count($points)];
    }
}
