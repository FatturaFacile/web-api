<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\Support;

final class Geo
{
    private const float EARTH_RADIUS_KM = 6371.0088;

    /**
     * Great-circle distance in kilometres.
     */
    public static function haversineKm(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $dLat = deg2rad($toLat - $fromLat);
        $dLng = deg2rad($toLng - $fromLng);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
