<?php

declare(strict_types=1);

use Modules\ResourcePlanning\Domain\Enums\BookingType;
use Modules\ResourcePlanning\Domain\Enums\SaturationBand;
use Modules\ResourcePlanning\Domain\ValueObjects\LoadCell;
use Modules\ResourcePlanning\Infrastructure\Models\ResourceAllocation;

function rpCell(float $net, float $hard, float $soft = 0.0): LoadCell
{
    return new LoadCell('2027-02-08', 40, 40 - $net, $net, $hard, $soft, $hard + $soft, $hard, 0);
}

it('computes the load as the booked share of the net capacity', function (): void {
    expect(SaturationBand::loadPercent(40, 30))->toBe(75.0)
        ->and(SaturationBand::loadPercent(32, 24))->toBe(75.0)
        ->and(SaturationBand::loadPercent(40, 0))->toBe(0.0)
        ->and(SaturationBand::loadPercent(0, 0))->toBe(0.0)
        ->and(SaturationBand::loadPercent(0, 4))->toBe(999.0)
        ->and(SaturationBand::loadPercent(1, 100))->toBe(999.0);
});

it('bands the load: green below 80, yellow from 80 to 100, red above 100', function (float $load, SaturationBand $band): void {
    expect(SaturationBand::fromLoad(40, $load * 0.4, $load))->toBe($band);
})->with([
    'idle' => [0.0, SaturationBand::Green],
    'below the yellow threshold' => [79.99, SaturationBand::Green],
    'at the yellow threshold' => [80.0, SaturationBand::Yellow],
    'full' => [100.0, SaturationBand::Yellow],
    'just over' => [100.01, SaturationBand::Red],
    'overloaded' => [150.0, SaturationBand::Red],
]);

it('has no band for a period without capacity and without bookings', function (): void {
    expect(SaturationBand::fromLoad(0, 0, 0))->toBe(SaturationBand::None)
        ->and(SaturationBand::fromLoad(0, 4, 999))->toBe(SaturationBand::Red);
});

it('counts tentative bookings in the load and flags only a load above 100 percent', function (): void {
    expect(rpCell(40, 20, 12)->loadPercent())->toBe(80.0)
        ->and(rpCell(40, 20, 12)->band())->toBe(SaturationBand::Yellow)
        ->and(rpCell(40, 20, 20)->isOverloaded())->toBeFalse()
        ->and(rpCell(40, 30, 12)->isOverloaded())->toBeTrue()
        ->and(rpCell(24, 30)->loadPercent())->toBe(125.0);
});

it('exposes a cell the way the API sends it', function (): void {
    $cell = rpCell(32, 24, 4)->toArray();

    expect($cell['net_available_hours'])->toBe(32.0)
        ->and($cell['leave_and_holiday_hours'])->toBe(8.0)
        ->and($cell['hard_booked_hours'])->toBe(24.0)
        ->and($cell['soft_booked_hours'])->toBe(4.0)
        ->and($cell['load_percent'])->toBe(87.5)
        ->and($cell['band'])->toBe('yellow')
        ->and($cell['is_overloaded'])->toBeFalse();
});

it('converts an allocation into the booking the load engine reads', function (): void {
    $allocation = new ResourceAllocation;
    $allocation->forceFill([
        'employee_id' => 'employee-1',
        'booking_type' => BookingType::HardCommitted,
        'start_date' => '2027-02-08',
        'end_date' => '2027-02-12',
        'allocated_hours_per_day' => '6.50',
        'is_billable' => true,
    ]);

    $booking = $allocation->toBooking();

    expect($booking->employeeId)->toBe('employee-1')
        ->and($booking->hoursPerDay)->toBe(6.5)
        ->and($booking->type->isCommitted())->toBeTrue()
        ->and($booking->billable)->toBeTrue();
});
