<?php

declare(strict_types=1);

use Modules\PassengerTransport\Domain\Enums\FlightStatus;
use Modules\PassengerTransport\Domain\Support\FlightScheduleAdjuster;
use Modules\PassengerTransport\Services\Flight\SimulatedFlightStatusProvider;

it('moves the pickup to the estimated arrival plus the meet-and-greet buffer', function (): void {
    $adjuster = new FlightScheduleAdjuster(30, 10);
    $pickup = new DateTimeImmutable('2028-03-10 14:30:00');

    $delayed = $adjuster->adjustedPickup($pickup, new DateTimeImmutable('2028-03-10 15:15:00'));
    $early = $adjuster->adjustedPickup($pickup, new DateTimeImmutable('2028-03-10 13:45:00'));

    expect($delayed?->format('H:i'))->toBe('15:45')
        ->and($early?->format('H:i'))->toBe('14:15');
});

it('ignores a drift below the threshold', function (): void {
    $adjuster = new FlightScheduleAdjuster(30, 10);

    expect($adjuster->adjustedPickup(new DateTimeImmutable('2028-03-10 14:30:00'), new DateTimeImmutable('2028-03-10 14:05:00')))->toBeNull()
        ->and($adjuster->adjustedPickup(new DateTimeImmutable('2028-03-10 14:30:00'), new DateTimeImmutable('2028-03-10 13:55:00')))->toBeNull();
});

it('never moves the pickup into the past on an early arrival', function (): void {
    $adjuster = new FlightScheduleAdjuster(30, 10);
    $now = new DateTimeImmutable('2028-03-10 14:20:00');

    $moved = $adjuster->adjustedPickup(new DateTimeImmutable('2028-03-10 15:00:00'), new DateTimeImmutable('2028-03-10 13:00:00'), $now);

    expect($moved?->format('H:i'))->toBe('14:20');
});

it('answers the same for the same flight in the simulator', function (): void {
    $scheduled = new DateTimeImmutable('2028-03-10 14:00:00');
    $provider = new SimulatedFlightStatusProvider(new DateTimeImmutable('2028-03-10 08:00:00'));

    $first = $provider->lookup('LH1234', 'LH', 'MXP', $scheduled);
    $second = $provider->lookup('LH1234', 'LH', 'MXP', $scheduled);

    expect($first)->toEqual($second)
        ->and($first?->status)->toBe(FlightStatus::Delayed)
        ->and($first?->delayMinutes)->toBe(45)
        ->and($first?->estimatedArrivalAt?->format('H:i'))->toBe('14:45');
});

it('simulates early, on time, heavily delayed and cancelled flights from the last digit', function (): void {
    $scheduled = new DateTimeImmutable('2028-03-10 14:00:00');
    $provider = new SimulatedFlightStatusProvider(new DateTimeImmutable('2028-03-10 08:00:00'));

    expect($provider->lookup('LX1722', null, 'ZRH', $scheduled)?->delayMinutes)->toBe(-15)
        ->and($provider->lookup('LX1727', null, 'ZRH', $scheduled)?->status)->toBe(FlightStatus::Scheduled)
        ->and($provider->lookup('LX1726', null, 'ZRH', $scheduled)?->delayMinutes)->toBe(120)
        ->and($provider->lookup('LX1720', null, 'ZRH', $scheduled)?->status)->toBe(FlightStatus::Cancelled)
        ->and($provider->lookup('LX', null, 'ZRH', $scheduled))->toBeNull();
});

it('reports landed once the estimated arrival has passed', function (): void {
    $provider = new SimulatedFlightStatusProvider(new DateTimeImmutable('2028-03-10 16:00:00'));

    expect($provider->lookup('LH1234', 'LH', 'MXP', new DateTimeImmutable('2028-03-10 14:00:00'))?->status)->toBe(FlightStatus::Landed);
});
