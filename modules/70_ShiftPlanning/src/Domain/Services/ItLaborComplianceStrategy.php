<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Modules\ShiftPlanning\Domain\Enums\Jurisdiction;
use Modules\ShiftPlanning\Domain\ValueObjects\ComplianceContext;
use Modules\ShiftPlanning\Domain\ValueObjects\ComplianceIssue;
use Modules\ShiftPlanning\Domain\ValueObjects\PlannedShift;
use Modules\ShiftPlanning\Domain\ValueObjects\SurchargeBreakdown;

/**
 * Italian working-time decree (D.Lgs. 66/2003) with the typical CCNL night period.
 *
 *  - Art. 7: 11 consecutive hours of daily rest between two shifts. A close back-to-back sequence such as an afternoon
 *    14:00-22:00 followed by a morning 06:00-14:00 leaves 8 hours and is blocked.
 *  - Art. 9: 24 hours of weekly rest, added to the daily 11: 35 consecutive hours every 7 days. A week without any free day
 *    is blocked; a week with a free day but no 35-hour rest is a warning (the CCNL may average it over 14 days).
 *  - Art. 13: a night worker (3+ night hours) works 8 hours at most in the 24 hours.
 * Night, Sunday and holiday minutes are tagged for payroll (Mod 077); the percentages are the ones of the CCNL and come
 * from config('shift_planning.italy'). The night period is 22:00-06:00.
 */
class ItLaborComplianceStrategy extends AbstractLaborComplianceStrategy
{
    public const int DAILY_REST_MINUTES = 660;

    public const int WEEKLY_REST_MINUTES = 2100;

    public const int WEEKLY_REST_WITHIN_WEEK_MINUTES = 1440;

    public const int NIGHT_WORKER_THRESHOLD_MINUTES = 180;

    public const int NIGHT_WORK_MAX_MINUTES = 480;

    public function jurisdiction(): Jurisdiction
    {
        return Jurisdiction::Italy;
    }

    protected function nightStartHour(): int
    {
        return 22;
    }

    protected function nightEndHour(): int
    {
        return 6;
    }

    public function validate(PlannedShift $candidate, array $employeeShifts, ComplianceContext $context): array
    {
        $issues = [];

        foreach ($this->adjacentRest($candidate, $employeeShifts) as $side => $minutes) {
            if ($minutes !== null && $minutes < self::DAILY_REST_MINUTES) {
                $issues[] = ComplianceIssue::blocking('IT.DAILY_REST_11H', ['rest_minutes' => $minutes, 'required_minutes' => self::DAILY_REST_MINUTES, 'side' => $side]);
            }
        }

        array_push($issues, ...$this->weeklyRestIssues($candidate, $employeeShifts));

        $night = $this->surchargeMinutes($candidate)['night'];

        if ($night >= self::NIGHT_WORKER_THRESHOLD_MINUTES && $candidate->workedMinutes() > self::NIGHT_WORK_MAX_MINUTES) {
            $issues[] = ComplianceIssue::warning('IT.NIGHT_WORK_MAX_8H', ['worked_minutes' => $candidate->workedMinutes(), 'maximum_minutes' => self::NIGHT_WORK_MAX_MINUTES]);
        }

        return $issues;
    }

    public function calculateSurcharges(PlannedShift $shift, ComplianceContext $context): SurchargeBreakdown
    {
        $minutes = $this->surchargeMinutes($shift);
        $night = $shift->nightSurchargeApplicable ? $minutes['night'] : 0;

        return new SurchargeBreakdown(
            workedMinutes: $shift->workedMinutes(),
            nightMinutes: $night,
            sundayMinutes: $minutes['sunday'],
            holidayMinutes: $minutes['holiday'],
            onCallMinutes: $shift->isOnCall ? $shift->workedMinutes() : 0,
            nightWageSurchargePercent: $night > 0 ? $this->percent('night_surcharge_percent') : null,
            sundayWageSurchargePercent: $minutes['sunday'] > 0 ? $this->percent('sunday_surcharge_percent') : null,
            holidayWageSurchargePercent: $minutes['holiday'] > 0 ? $this->percent('holiday_surcharge_percent') : null,
        );
    }

    private function percent(string $key): float
    {
        return (float) config('shift_planning.italy.'.$key, 0.0);
    }

    /**
     * @param  list<PlannedShift>  $others
     * @return list<ComplianceIssue>
     */
    private function weeklyRestIssues(PlannedShift $candidate, array $others): array
    {
        $weekStart = $candidate->startAt->setTimezone($this->timezone())->startOfWeek(CarbonInterface::MONDAY);
        $weekEnd = $weekStart->addWeek()->startOfDay();
        $shifts = [...$others, $candidate];

        if ($this->hasWeeklyRest($shifts, $weekStart, $weekEnd)) {
            return [];
        }

        $worked = $this->workingDates($shifts);

        for ($day = $weekStart; $day < $weekEnd; $day = $day->addDay()->startOfDay()) {
            if (! isset($worked[$day->format('Y-m-d')])) {
                return [ComplianceIssue::warning('IT.WEEKLY_REST_35H', ['week_start' => $weekStart->format('Y-m-d')])];
            }
        }

        return [ComplianceIssue::blocking('IT.WEEKLY_REST_24H', ['week_start' => $weekStart->format('Y-m-d')])];
    }

    /**
     * A rest of 35 consecutive hours of which at least 24 fall in the week.
     *
     * @param  list<PlannedShift>  $shifts
     */
    private function hasWeeklyRest(array $shifts, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): bool
    {
        usort($shifts, static fn (PlannedShift $a, PlannedShift $b): int => $a->startAt <=> $b->startAt);
        $gaps = $this->gaps($shifts);
        $first = $shifts[0];
        $last = $shifts[0];

        foreach ($shifts as $shift) {
            $last = $shift->endAt > $last->endAt ? $shift : $last;
        }

        // Before the first and after the last known shift the employee is free for as long as the plan says nothing.
        $gaps[] = ['start' => $first->startAt->subDays(30), 'end' => $first->startAt, 'minutes' => 30 * 1440];
        $gaps[] = ['start' => $last->endAt, 'end' => $last->endAt->addDays(30), 'minutes' => 30 * 1440];

        foreach ($gaps as $gap) {
            $inside = min($gap['end']->getTimestamp(), $weekEnd->getTimestamp()) - max($gap['start']->getTimestamp(), $weekStart->getTimestamp());

            if ($gap['minutes'] >= self::WEEKLY_REST_MINUTES && $inside >= self::WEEKLY_REST_WITHIN_WEEK_MINUTES * 60) {
                return true;
            }
        }

        return false;
    }
}
