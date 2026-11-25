<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Contracts;

/**
 * Road distance and driving time between two points. The implementation is chosen with config('passenger_transport.routing.driver').
 */
interface RoutingProviderInterface
{
    /**
     * @return array{km: string, minutes: int, source: string}
     */
    public function leg(float $fromLat, float $fromLng, float $toLat, float $toLng): array;
}
