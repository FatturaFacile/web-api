<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Services\Routing;

use Illuminate\Support\Facades\Http;
use Modules\PassengerTransport\Contracts\RoutingProviderInterface;
use Modules\PassengerTransport\Domain\Support\Dec;
use Throwable;

/**
 * Google Maps Distance Matrix API. Falls back to the deterministic provider when the key is missing or the call fails.
 */
final class GoogleMapsRoutingProvider implements RoutingProviderInterface
{
    public function __construct(private readonly ?string $apiKey, private readonly HaversineRoutingProvider $fallback, private readonly int $timeoutSeconds = 5) {}

    public function leg(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        if ($this->apiKey !== null && $this->apiKey !== '') {
            try {
                $response = Http::timeout($this->timeoutSeconds)->acceptJson()->get('https://maps.googleapis.com/maps/api/distancematrix/json', [
                    'origins' => sprintf('%F,%F', $fromLat, $fromLng),
                    'destinations' => sprintf('%F,%F', $toLat, $toLng),
                    'mode' => 'driving',
                    'key' => $this->apiKey,
                ]);
                $element = $response->json('rows.0.elements.0');

                if ($response->successful() && ($element['status'] ?? null) === 'OK') {
                    return ['km' => Dec::round(Dec::div((string) $element['distance']['value'], '1000')), 'minutes' => (int) ceil(((float) $element['duration']['value']) / 60), 'source' => 'google'];
                }
            } catch (Throwable) {
                // falls through to the deterministic estimate
            }
        }

        return $this->fallback->leg($fromLat, $fromLng, $toLat, $toLng);
    }
}
