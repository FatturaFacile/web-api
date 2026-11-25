<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Services;

use Modules\PassengerTransport\Contracts\RoutingProviderInterface;
use Modules\PassengerTransport\Domain\Enums\BorderCrossing;
use Modules\PassengerTransport\Domain\Support\Dec;
use Modules\PassengerTransport\Domain\Support\Geo;
use Modules\PassengerTransport\Domain\ValueObjects\RouteSplit;

/**
 * Splits a trip in the kilometres driven in Italy, in Switzerland and elsewhere. A trip inside one country stays whole; a trip
 * between two countries goes through a border crossing (the one given, or the one that makes the shortest detour) and each leg is
 * assigned to the country it starts in: pickup country up to the crossing, destination country after it.
 */
final class CrossBorderRouteSplitCalculatorService
{
    public function __construct(private readonly RoutingProviderInterface $routing) {}

    public function calculate(
        float $pickupLat,
        float $pickupLng,
        string $pickupCountry,
        float $dropoffLat,
        float $dropoffLng,
        string $dropoffCountry,
        ?BorderCrossing $crossing = null,
    ): RouteSplit {
        $pickupCountry = strtoupper($pickupCountry);
        $dropoffCountry = strtoupper($dropoffCountry);
        $buckets = ['IT' => '0', 'CH' => '0', 'OTHER' => '0'];

        if ($pickupCountry === $dropoffCountry) {
            $leg = $this->routing->leg($pickupLat, $pickupLng, $dropoffLat, $dropoffLng);
            $buckets[$this->bucket($pickupCountry)] = $leg['km'];

            return $this->result($buckets, BorderCrossing::None, $leg['minutes'], $leg['source']);
        }

        $crossing = $crossing === null || $crossing === BorderCrossing::None
            ? $this->nearestCrossing($pickupLat, $pickupLng, $dropoffLat, $dropoffLng)
            : $crossing;
        $border = $crossing->coordinates();
        $toBorder = $this->routing->leg($pickupLat, $pickupLng, $border['lat'], $border['lng']);
        $fromBorder = $this->routing->leg($border['lat'], $border['lng'], $dropoffLat, $dropoffLng);

        $buckets[$this->bucket($pickupCountry)] = Dec::add($buckets[$this->bucket($pickupCountry)], $toBorder['km']);
        $buckets[$this->bucket($dropoffCountry)] = Dec::add($buckets[$this->bucket($dropoffCountry)], $fromBorder['km']);

        return $this->result($buckets, $crossing, $toBorder['minutes'] + $fromBorder['minutes'], $toBorder['source']);
    }

    /**
     * The crossing with the shortest straight-line detour; deterministic, so the same trip always yields the same split.
     */
    public function nearestCrossing(float $pickupLat, float $pickupLng, float $dropoffLat, float $dropoffLng): BorderCrossing
    {
        $best = BorderCrossing::ChiassoBrogeda;
        $bestKm = PHP_FLOAT_MAX;

        foreach (BorderCrossing::crossings() as $crossing) {
            $point = $crossing->coordinates();
            $km = Geo::haversineKm($pickupLat, $pickupLng, $point['lat'], $point['lng']) + Geo::haversineKm($point['lat'], $point['lng'], $dropoffLat, $dropoffLng);

            if ($km < $bestKm) {
                $best = $crossing;
                $bestKm = $km;
            }
        }

        return $best;
    }

    private function bucket(string $country): string
    {
        return in_array($country, ['IT', 'CH'], true) ? $country : 'OTHER';
    }

    /**
     * @param  array{IT: string, CH: string, OTHER: string}  $buckets
     */
    private function result(array $buckets, BorderCrossing $crossing, int $minutes, string $source): RouteSplit
    {
        $it = Dec::round($buckets['IT']);
        $ch = Dec::round($buckets['CH']);
        $other = Dec::round($buckets['OTHER']);

        return new RouteSplit(Dec::round(Dec::add(Dec::add($it, $ch), $other)), $it, $ch, $other, $crossing, $minutes, $source);
    }
}
