<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Services\Routing;

use Illuminate\Support\Facades\Http;
use Modules\PassengerTransport\Contracts\RoutingProviderInterface;
use Modules\PassengerTransport\Domain\Support\Dec;
use Throwable;

/**
 * OSRM (or a Valhalla instance exposing the OSRM route API) over HTTP. Any failure falls back to the deterministic provider so a
 * quotation never fails because the routing server is down.
 */
final class OsrmRoutingProvider implements RoutingProviderInterface
{
    public function __construct(private readonly string $baseUrl, private readonly HaversineRoutingProvider $fallback, private readonly int $timeoutSeconds = 5) {}

    public function leg(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        try {
            $response = Http::timeout($this->timeoutSeconds)->acceptJson()->get(sprintf('%s/route/v1/driving/%F,%F;%F,%F', rtrim($this->baseUrl, '/'), $fromLng, $fromLat, $toLng, $toLat), ['overview' => 'false']);
            $route = $response->json('routes.0');

            if ($response->successful() && is_array($route) && isset($route['distance'], $route['duration'])) {
                return ['km' => Dec::round(Dec::div((string) $route['distance'], '1000')), 'minutes' => (int) ceil(((float) $route['duration']) / 60), 'source' => 'osrm'];
            }
        } catch (Throwable) {
            // falls through to the deterministic estimate
        }

        return $this->fallback->leg($fromLat, $fromLng, $toLat, $toLng);
    }
}
