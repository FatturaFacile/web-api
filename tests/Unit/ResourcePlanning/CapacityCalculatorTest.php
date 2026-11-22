<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\ResourcePlanning\Domain\Contracts\HolidayPort;
use Modules\ResourcePlanning\Domain\Services\CapacityCalculator;
use Modules\ResourcePlanning\Domain\ValueObjects\HolidayDay;
use Modules\ResourcePlanning\Domain\ValueObjects\LeaveSpan;
use Modules\ResourcePlanning\Domain\ValueObjects\ResourceProfile;

/**
 * @param  array<string, HolidayDay>  $holidays  keyed by Y-m-d, returned for every location and year
 */
function rpCalculator(array $holidays = []): CapacityCalculator
{
    return new CapacityCalculator(new class($holidays) implements HolidayPort
    {
        /** @param array<string, HolidayDay> $days */
        public function __construct(private readonly array $days) {}

        public function holidays(string $country, ?string $region, ?string $municipality, int $year): array
        {
            return $this->days;
        }
    });
}

/**
 * @param  list<int>  $weekdays
 */
function rpProfile(float $weeklyHours = 40.0, array $weekdays = [1, 2, 3, 4, 5]): ResourceProfile
{
    return new ResourceProfile('employee-1', 'Anna Rossi', $weeklyHours, $weekdays, 1.0, 50.0, null, null, null, null, 'CH', 'TI', null);
}

function rpDay(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date)->startOfDay();
}

it('spreads the contractual week over the weekdays worked', function (): void {
    $days = rpCalculator()->daily(rpProfile(), rpDay('2027-02-08'), rpDay('2027-02-14'), []);

    expect(array_map(static fn ($day): float => $day->netHours(), array_values($days)))->toBe([8.0, 8.0, 8.0, 8.0, 8.0, 0.0, 0.0])
        ->and(array_sum(array_map(static fn ($day): float => $day->contractualHours, $days)))->toBe(40.0);
});

it('gives a part-timer the hours of the days worked only', function (): void {
    $days = rpCalculator()->daily(rpProfile(24.0, [1, 2, 3]), rpDay('2027-02-08'), rpDay('2027-02-14'), []);

    expect($days['2027-02-08']->netHours())->toBe(8.0)
        ->and($days['2027-02-10']->netHours())->toBe(8.0)
        ->and($days['2027-02-11']->netHours())->toBe(0.0)
        ->and($days['2027-02-11']->isBookable())->toBeFalse();
});

it('deducts a full holiday and removes the day from the bookable days', function (): void {
    $calculator = rpCalculator(['2027-05-06' => new HolidayDay('Ascension')]);
    $days = $calculator->daily(rpProfile(), rpDay('2027-05-03'), rpDay('2027-05-09'), []);

    expect($days['2027-05-06']->holidayHours)->toBe(8.0)
        ->and($days['2027-05-06']->netHours())->toBe(0.0)
        ->and($days['2027-05-06']->isFullHoliday)->toBeTrue()
        ->and($days['2027-05-06']->isBookable())->toBeFalse()
        ->and($days['2027-05-05']->isBookable())->toBeTrue();
});

it('deducts a half-day holiday by its fraction', function (): void {
    $calculator = rpCalculator(['2027-12-24' => new HolidayDay('Christmas Eve', 0.5)]);
    $day = $calculator->daily(rpProfile(), rpDay('2027-12-24'), rpDay('2027-12-24'), [])['2027-12-24'];

    expect($day->holidayHours)->toBe(4.0)->and($day->netHours())->toBe(4.0)->and($day->isBookable())->toBeTrue();
});

it('ignores a holiday on a day off', function (): void {
    $calculator = rpCalculator(['2027-02-13' => new HolidayDay('Saturday feast')]);
    $day = $calculator->daily(rpProfile(), rpDay('2027-02-13'), rpDay('2027-02-13'), [])['2027-02-13'];

    expect($day->holidayHours)->toBe(0.0)->and($day->netHours())->toBe(0.0);
});

it('removes whole days of approved leave from the net hours but keeps them bookable', function (): void {
    $leave = [new LeaveSpan(rpDay('2027-02-09'), rpDay('2027-02-10'))];
    $days = rpCalculator()->daily(rpProfile(), rpDay('2027-02-08'), rpDay('2027-02-14'), $leave);

    expect($days['2027-02-09']->leaveHours)->toBe(8.0)
        ->and($days['2027-02-09']->netHours())->toBe(0.0)
        ->and($days['2027-02-09']->isBookable())->toBeTrue()
        ->and($days['2027-02-11']->netHours())->toBe(8.0);
});

it('does not take leave on a holiday or on a day off twice', function (): void {
    $calculator = rpCalculator(['2027-05-06' => new HolidayDay('Ascension')]);
    $leave = [new LeaveSpan(rpDay('2027-05-05'), rpDay('2027-05-09'))];
    $days = $calculator->daily(rpProfile(), rpDay('2027-05-03'), rpDay('2027-05-09'), $leave);

    expect($days['2027-05-06']->leaveHours)->toBe(0.0)
        ->and($days['2027-05-08']->leaveHours)->toBe(0.0)
        ->and(array_sum(array_map(static fn ($day): float => $day->leaveHours, $days)))->toBe(16.0)
        ->and(array_sum(array_map(static fn ($day): float => $day->netHours(), $days)))->toBe(16.0);
});

it('spreads the hours of a partial leave over its working days', function (): void {
    $leave = [new LeaveSpan(rpDay('2027-02-08'), rpDay('2027-02-10'), 12.0)];
    $days = rpCalculator()->daily(rpProfile(), rpDay('2027-02-08'), rpDay('2027-02-14'), $leave);

    expect($days['2027-02-08']->leaveHours)->toBe(4.0)
        ->and($days['2027-02-09']->leaveHours)->toBe(4.0)
        ->and($days['2027-02-10']->netHours())->toBe(4.0);
});

it('caps a partial leave at the capacity of its span and ignores overlapping leave beyond the hours left', function (): void {
    $leave = [new LeaveSpan(rpDay('2027-02-08'), rpDay('2027-02-08'), 20.0), new LeaveSpan(rpDay('2027-02-08'), rpDay('2027-02-08'))];
    $day = rpCalculator()->daily(rpProfile(), rpDay('2027-02-08'), rpDay('2027-02-08'), $leave)['2027-02-08'];

    expect($day->leaveHours)->toBe(8.0)->and($day->netHours())->toBe(0.0);
});

it('counts the leave of a span that starts before the window', function (): void {
    $leave = [new LeaveSpan(rpDay('2027-02-04'), rpDay('2027-02-09'), 24.0)];
    $days = rpCalculator()->daily(rpProfile(), rpDay('2027-02-08'), rpDay('2027-02-14'), $leave);

    // Four working days from Thursday 4 to Tuesday 9 February would be 32 hours; 24 hours is three quarters of each day.
    expect($days['2027-02-08']->leaveHours)->toBe(6.0)->and($days['2027-02-09']->leaveHours)->toBe(6.0)->and($days['2027-02-10']->leaveHours)->toBe(0.0);
});

it('has no capacity without contractual hours or weekdays', function (): void {
    expect(rpCalculator()->contractualHoursOn(rpProfile(0.0), rpDay('2027-02-08')))->toBe(0.0)
        ->and(rpCalculator()->contractualHoursOn(rpProfile(40.0, []), rpDay('2027-02-08')))->toBe(0.0);
});

it('asks the calendar once per location and year', function (): void {
    $port = new class implements HolidayPort
    {
        public int $calls = 0;

        public function holidays(string $country, ?string $region, ?string $municipality, int $year): array
        {
            $this->calls++;

            return [];
        }
    };

    (new CapacityCalculator($port))->daily(rpProfile(), rpDay('2027-02-08'), rpDay('2027-03-14'), []);

    expect($port->calls)->toBe(1);
});
