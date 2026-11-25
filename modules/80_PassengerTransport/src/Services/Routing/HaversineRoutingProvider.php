<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Services\Routing;

use Modules\PassengerTransport\Contracts\RoutingProviderInterface;
use Modules\PassengerTransport\Domain\Support\Dec;
use Modules\PassengerTransport\Domain\Support\Geo;

/**
 * Deterministic fallback that needs no external key: the great-circle distance multiplied by a road factor, at an average speed.
 */
final class HaversineRoutingProvider implements RoutingProviderInterface
{
    public function __construct(private readonly float $roadFactor = 1.3, private readonly float $averageSpeedKmh = 60.0) {}

    public function leg(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        $km = Geo::haversineKm($fromLat, $fromLng, $toLat, $toLng) * $this->roadFactor;

        return [
            'km' => Dec::round($km),
            'minutes' => (int) ceil($km / max(1.0, $this->averageSpeedKmh) * 60),
            'source' => 'haversine',
        ];
    }
}
