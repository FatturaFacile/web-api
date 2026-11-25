<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\Strategies;

use Modules\PassengerTransport\Domain\Enums\DrivingRegime;
use Modules\PassengerTransport\Domain\Support\Dec;
use Modules\PassengerTransport\Domain\Support\DrivingTimeEvaluator;
use Modules\PassengerTransport\Domain\ValueObjects\DriverCounters;
use Modules\PassengerTransport\Domain\ValueObjects\TripPlan;

/**
 * Shared by the two strategies: the regime of a vehicle and the pro-rata split of a fare by kilometres.
 */
abstract class AbstractPassengerTransportStrategy implements IPassengerTransportRegulatoryStrategy
{
    public function __construct(protected readonly DrivingTimeEvaluator $driving = new DrivingTimeEvaluator) {}

    public function drivingRegime(float $grossWeightTons, int $passengerCapacity): DrivingRegime
    {
        return $grossWeightTons > 3.5 || $passengerCapacity + 1 > 9 ? DrivingRegime::Olr1Ec561 : DrivingRegime::Olr2;
    }

    public function evaluateDriving(DrivingRegime $regime, DriverCounters $counters, TripPlan $trip, bool $tripTouchesCh): array
    {
        return $this->driving->evaluate($regime, $counters, $trip);
    }

    /**
     * Splits the net fare between the kilometres of a territory and the rest: the rest is rounded, the territory takes the remainder, so
     * the lines always add up to the agreed fare.
     *
     * @return array{0: string, 1: string} [net of the territory, net of the rest]
     */
    protected function split(string $net, string $territoryKm, string $totalKm): array
    {
        if (Dec::cmp($totalKm, '0') <= 0) {
            return [Dec::round($net), '0.00'];
        }

        $rest = Dec::round(Dec::mul($net, Dec::div(Dec::sub($totalKm, $territoryKm), $totalKm)));

        return [Dec::round(Dec::sub($net, $rest)), $rest];
    }

    protected function share(string $km, string $totalKm): string
    {
        return Dec::cmp($totalKm, '0') <= 0 ? '0.0000' : Dec::round(Dec::div($km, $totalKm), 4);
    }

    protected function tax(string $net, string $rate): string
    {
        return Dec::round(Dec::div(Dec::mul($net, $rate), '100'));
    }
}
