<?php

declare(strict_types=1);

use Modules\PassengerTransport\Contracts\RoutingProviderInterface;
use Modules\PassengerTransport\Domain\Enums\BorderCrossing;
use Modules\PassengerTransport\Domain\Support\Dec;
use Modules\PassengerTransport\Domain\Support\Geo;
use Modules\PassengerTransport\Services\CrossBorderRouteSplitCalculatorService;
use Modules\PassengerTransport\Services\Routing\HaversineRoutingProvider;

/**
 * A routing provider with a fixed road factor and speed, so that the expected figures can be computed by hand.
 */
function ptrFlatRouting(): RoutingProviderInterface
{
    return new class implements RoutingProviderInterface
    {
        public function leg(float $fromLat, float $fromLng, float $toLat, float $toLng): array
        {
            $km = Geo::haversineKm($fromLat, $fromLng, $toLat, $toLng);

            return ['km' => Dec::round($km), 'minutes' => (int) ceil($km), 'source' => 'flat'];
        }
    };
}

it('measures great-circle distances', function (): void {
    expect(Geo::haversineKm(45.0, 9.0, 45.0, 9.0))->toBe(0.0)
        ->and(Geo::haversineKm(45.6306, 8.7281, 46.0037, 8.9511))->toBeBetween(39.0, 45.0)
        ->and(Geo::haversineKm(0.0, 0.0, 0.0, 1.0))->toBeBetween(111.0, 111.4);
});

it('keeps a trip inside one country whole and without a crossing', function (): void {
    $split = (new CrossBorderRouteSplitCalculatorService(ptrFlatRouting()))->calculate(45.6306, 8.7281, 'IT', 45.4642, 9.1900, 'IT');

    expect($split->crossing)->toBe(BorderCrossing::None)
        ->and(Dec::cmp($split->itKm, '30'))->toBe(1)
        ->and($split->chKm)->toBe('0.00')
        ->and($split->otherEuKm)->toBe('0.00')
        ->and($split->totalKm)->toBe($split->itKm);
});

it('splits Milano Malpensa to Lugano at the border: Italian km up to Chiasso, Swiss km after it', function (): void {
    $split = (new CrossBorderRouteSplitCalculatorService(ptrFlatRouting()))->calculate(45.6306, 8.7281, 'IT', 46.0037, 8.9511, 'CH', BorderCrossing::ChiassoBrogeda);

    $itLeg = Dec::round(Geo::haversineKm(45.6306, 8.7281, 45.8296, 9.0316));
    $chLeg = Dec::round(Geo::haversineKm(45.8296, 9.0316, 46.0037, 8.9511));

    expect($split->crossing)->toBe(BorderCrossing::ChiassoBrogeda)
        ->and($split->itKm)->toBe($itLeg)
        ->and($split->chKm)->toBe($chLeg)
        ->and($split->totalKm)->toBe(Dec::round(Dec::add($itLeg, $chLeg)))
        ->and($split->durationMinutes)->toBeGreaterThan(0)
        ->and($split->toArray())->toHaveKeys(['total_distance_km', 'it_territory_km', 'ch_territory_km', 'other_eu_territory_km', 'border_crossing_point', 'estimated_duration_minutes', 'routing_source']);
});

it('assigns the first leg to the country of the pickup, so Zurich to Milano puts the Swiss km first', function (): void {
    $split = (new CrossBorderRouteSplitCalculatorService(ptrFlatRouting()))->calculate(47.4647, 8.5492, 'CH', 45.4642, 9.1900, 'IT', BorderCrossing::ChiassoBrogeda);

    expect(Dec::cmp($split->chKm, $split->itKm))->toBe(1)
        ->and(Dec::cmp($split->itKm, '0'))->toBe(1);
});

it('picks the crossing with the shortest detour when none is given', function (): void {
    $calculator = new CrossBorderRouteSplitCalculatorService(ptrFlatRouting());

    expect($calculator->nearestCrossing(45.6306, 8.7281, 46.0037, 8.9511))->toBeIn([BorderCrossing::ChiassoBrogeda, BorderCrossing::Gaggiolo, BorderCrossing::PonteTresa, BorderCrossing::PortoCeresio])
        ->and($calculator->nearestCrossing(45.7, 7.3, 46.2, 7.0))->toBe(BorderCrossing::GranSanBernardo)
        ->and($calculator->nearestCrossing(46.17, 9.87, 46.5, 10.3))->toBe(BorderCrossing::Tirano);
});

it('books the kilometres of a third country apart from Italy and Switzerland', function (): void {
    $split = (new CrossBorderRouteSplitCalculatorService(ptrFlatRouting()))->calculate(47.4647, 8.5492, 'CH', 48.1351, 11.5820, 'DE', BorderCrossing::Castasegna);

    expect(Dec::cmp($split->otherEuKm, '0'))->toBe(1)
        ->and(Dec::cmp($split->chKm, '0'))->toBe(1)
        ->and($split->itKm)->toBe('0.00');
});

it('estimates with the deterministic fallback when no key is configured', function (): void {
    $leg = (new HaversineRoutingProvider(1.3, 65.0))->leg(45.6306, 8.7281, 46.0037, 8.9511);

    expect($leg['source'])->toBe('haversine')
        ->and(Dec::cmp($leg['km'], '50'))->toBe(1)
        ->and($leg['minutes'])->toBeGreaterThan(40);
});

it('lists every crossing but "none" for the automatic choice and gives each a position', function (): void {
    expect(BorderCrossing::crossings())->toHaveCount(8)
        ->and(BorderCrossing::None->coordinates())->toBeNull()
        ->and(BorderCrossing::Gaggiolo->coordinates())->toHaveKeys(['lat', 'lng']);
});
