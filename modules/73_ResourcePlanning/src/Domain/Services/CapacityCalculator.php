<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Domain\Services;

use Carbon\CarbonImmutable;
use Modules\ResourcePlanning\Domain\Contracts\HolidayPort;
use Modules\ResourcePlanning\Domain\ValueObjects\DailyCapacity;
use Modules\ResourcePlanning\Domain\ValueObjects\HolidayDay;
use Modules\ResourcePlanning\Domain\ValueObjects\LeaveSpan;
use Modules\ResourcePlanning\Domain\ValueObjects\ResourceProfile;

/**
 * Exact net capacity of an employee, day by day: the contractual week (part-time and FTE already in it) spread over the
 * weekdays worked, minus the public holidays of the working location (national, Swiss cantonal, Italian regional and
 * municipal patron saint) and the approved leave. Pure and deterministic: every input comes through the ports.
 */
final class CapacityCalculator
{
    /** @var array<string, array<string, HolidayDay>> */
    private array $holidayCache = [];

    public function __construct(private readonly HolidayPort $holidays) {}

    /**
     * The hours of a regular working day of the employee, before holidays and leave (0 on a day off).
     */
    public function contractualHoursOn(ResourceProfile $profile, CarbonImmutable $day): float
    {
        $workDays = count($profile->workWeekdays);

        if ($workDays === 0 || $profile->weeklyHours <= 0.0) {
            return 0.0;
        }

        return in_array($day->dayOfWeekIso, $profile->workWeekdays, true) ? $profile->weeklyHours / $workDays : 0.0;
    }

    public function holidayOn(ResourceProfile $profile, CarbonImmutable $day): ?HolidayDay
    {
        $key = implode('|', [$profile->country, (string) $profile->region, (string) $profile->municipality, $day->year]);
        $this->holidayCache[$key] ??= $this->holidays->holidays($profile->country, $profile->region, $profile->municipality, $day->year);

        return $this->holidayCache[$key][$day->format('Y-m-d')] ?? null;
    }

    /**
     * @param  list<LeaveSpan>  $leave  approved leave of the employee (any span, also outside the window)
     * @return array<string, DailyCapacity> keyed by Y-m-d, every day from $from to $to
     */
    public function daily(ResourceProfile $profile, CarbonImmutable $from, CarbonImmutable $to, array $leave): array
    {
        /** @var array<string, array{base: float, holiday: float, leave: float, full: bool}> $days */
        $days = [];

        for ($day = $from->startOfDay(); $day <= $to; $day = $day->addDay()) {
            $base = $this->contractualHoursOn($profile, $day);
            $holiday = $base > 0.0 ? $this->holidayOn($profile, $day) : null;
            $fraction = $holiday === null ? 0.0 : min(1.0, max(0.0, $holiday->fraction));
            $days[$day->format('Y-m-d')] = ['base' => $base, 'holiday' => $base * $fraction, 'leave' => 0.0, 'full' => $holiday !== null && $fraction >= 1.0];
        }

        foreach ($leave as $span) {
            $fraction = $this->leaveFraction($profile, $span, $days);

            for ($day = max($from, $span->from)->startOfDay(); $day <= min($to, $span->to); $day = $day->addDay()) {
                $key = $day->format('Y-m-d');
                $remaining = $days[$key]['base'] - $days[$key]['holiday'] - $days[$key]['leave'];
                $days[$key]['leave'] += max(0.0, $remaining) * $fraction;
            }
        }

        $capacities = [];

        foreach ($days as $date => $values) {
            $capacities[$date] = new DailyCapacity($date, $values['base'], $values['holiday'], $values['leave'], $values['full']);
        }

        return $capacities;
    }

    /**
     * Whole-day leave takes all the remaining hours of its days; a leave with a total of hours takes that share of the
     * working capacity of the whole span.
     *
     * @param  array<string, array{base: float, holiday: float, leave: float, full: bool}>  $days  the days of the window already computed
     */
    private function leaveFraction(ResourceProfile $profile, LeaveSpan $span, array $days): float
    {
        if ($span->totalHours === null) {
            return 1.0;
        }

        $capacity = 0.0;

        for ($day = $span->from->startOfDay(); $day <= $span->to; $day = $day->addDay()) {
            $key = $day->format('Y-m-d');

            if (isset($days[$key])) {
                $capacity += max(0.0, $days[$key]['base'] - $days[$key]['holiday'] - $days[$key]['leave']);

                continue;
            }

            $base = $this->contractualHoursOn($profile, $day);
            $holiday = $base > 0.0 ? $this->holidayOn($profile, $day) : null;
            $capacity += $base * (1.0 - ($holiday === null ? 0.0 : min(1.0, max(0.0, $holiday->fraction))));
        }

        return $capacity <= 0.0 ? 0.0 : min(1.0, max(0.0, $span->totalHours) / $capacity);
    }
}
