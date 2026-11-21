<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Services;

use Modules\ShiftPlanning\Domain\Enums\Jurisdiction;
use Modules\ShiftPlanning\Domain\ValueObjects\ComplianceContext;
use Modules\ShiftPlanning\Domain\ValueObjects\ComplianceIssue;
use Modules\ShiftPlanning\Domain\ValueObjects\PlannedShift;
use Modules\ShiftPlanning\Domain\ValueObjects\SurchargeBreakdown;

/**
 * Swiss Labour Act (ArG / LL) and its ordinances.
 *
 *  - Art. 15a ArGV 1: daily rest of 11 consecutive hours; it may drop to 8 hours once per week if the average over two
 *    weeks is 11. Below 8 hours, or a second reduction in the week, blocks the assignment.
 *  - Art. 21 ArG: no more than six consecutive working days.
 *  - Art. 18-20 ArG: Sunday work needs a permit and a compensatory rest in the week before or after.
 *  - Art. 17b ArG: temporary night work earns a 25% wage supplement, regular night work 10% time compensation.
 *  - Art. 19/20a ArG: Sunday and holiday work earn a 50% supplement.
 * Night work is the period between 23:00 and 06:00 (Art. 10 ArG).
 */
class ChLaborComplianceStrategy extends AbstractLaborComplianceStrategy
{
    public const int DAILY_REST_MINUTES = 660;

    public const int REDUCED_DAILY_REST_MINUTES = 480;

    public const int MAX_CONSECUTIVE_DAYS = 6;

    public const float TEMPORARY_NIGHT_SURCHARGE_PERCENT = 25.0;

    public const float REGULAR_NIGHT_TIME_COMPENSATION = 0.10;

    public const float SUNDAY_SURCHARGE_PERCENT = 50.0;

    public function jurisdiction(): Jurisdiction
    {
        return Jurisdiction::Switzerland;
    }

    protected function nightStartHour(): int
    {
        return 23;
    }

    protected function nightEndHour(): int
    {
        return 6;
    }

    public function validate(PlannedShift $candidate, array $employeeShifts, ComplianceContext $context): array
    {
        return [
            ...$this->dailyRestIssues($candidate, $employeeShifts),
            ...$this->consecutiveDayIssues($candidate, $employeeShifts),
            ...$this->sundayIssues($candidate, $employeeShifts),
        ];
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
            nightWageSurchargePercent: $night > 0 && ! $context->regularNightWork ? self::TEMPORARY_NIGHT_SURCHARGE_PERCENT : null,
            nightTimeCompensationMinutes: $night > 0 && $context->regularNightWork ? (int) round($night * self::REGULAR_NIGHT_TIME_COMPENSATION) : 0,
            sundayWageSurchargePercent: $minutes['sunday'] > 0 ? self::SUNDAY_SURCHARGE_PERCENT : null,
            holidayWageSurchargePercent: $minutes['holiday'] > 0 ? self::SUNDAY_SURCHARGE_PERCENT : null,
        );
    }

    /**
     * @param  list<PlannedShift>  $others
     * @return list<ComplianceIssue>
     */
    private function dailyRestIssues(PlannedShift $candidate, array $others): array
    {
        $issues = [];
        $reductions = $this->reducedRestsAround($candidate, $others);

        foreach ($this->adjacentRest($candidate, $others) as $side => $minutes) {
            if ($minutes === null || $minutes >= self::DAILY_REST_MINUTES) {
                continue;
            }

            $params = ['rest_minutes' => $minutes, 'required_minutes' => self::DAILY_REST_MINUTES, 'side' => $side];
            $issues[] = $minutes < self::REDUCED_DAILY_REST_MINUTES || $reductions > 1
                ? ComplianceIssue::blocking('CH.DAILY_REST_11H', $params)
                : ComplianceIssue::warning('CH.DAILY_REST_REDUCED', $params);
        }

        return $issues;
    }

    /**
     * Rests between 8 and 11 hours in the week around the candidate, the candidate's own rests included.
     *
     * @param  list<PlannedShift>  $others
     */
    private function reducedRestsAround(PlannedShift $candidate, array $others): int
    {
        $from = $candidate->startAt->subDays(7);
        $to = $candidate->endAt->addDays(7);
        $count = 0;

        foreach ($this->gaps([...$others, $candidate]) as $gap) {
            if ($gap['start'] >= $from && $gap['start'] <= $to && $gap['minutes'] >= self::REDUCED_DAILY_REST_MINUTES && $gap['minutes'] < self::DAILY_REST_MINUTES) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<PlannedShift>  $others
     * @return list<ComplianceIssue>
     */
    private function consecutiveDayIssues(PlannedShift $candidate, array $others): array
    {
        $run = $this->consecutiveWorkingDays($candidate, $others);

        return $run > self::MAX_CONSECUTIVE_DAYS
            ? [ComplianceIssue::blocking('CH.MAX_CONSECUTIVE_DAYS', ['consecutive_days' => $run, 'maximum_days' => self::MAX_CONSECUTIVE_DAYS])]
            : [];
    }

    /**
     * @param  list<PlannedShift>  $others
     * @return list<ComplianceIssue>
     */
    private function sundayIssues(PlannedShift $candidate, array $others): array
    {
        if ($this->surchargeMinutes($candidate)['sunday'] <= 0) {
            return [];
        }

        $issues = [ComplianceIssue::warning('CH.SUNDAY_WORK_PERMIT')];
        $worked = $this->workingDates([...$others, $candidate]);
        $sunday = $candidate->startAt->setTimezone($this->timezone())->startOfDay();

        // The shift may have started on Saturday night: the Sunday is the one the shift touches.
        if ($sunday->isoWeekday() !== 7) {
            $sunday = $sunday->addDay();
        }

        $freeDayExists = false;

        for ($offset = 1; $offset <= 6 && ! $freeDayExists; $offset++) {
            $freeDayExists = ! isset($worked[$sunday->addDays($offset)->format('Y-m-d')]) || ! isset($worked[$sunday->subDays($offset)->format('Y-m-d')]);
        }

        if (! $freeDayExists) {
            $issues[] = ComplianceIssue::warning('CH.SUNDAY_COMPENSATORY_REST', ['sunday' => $sunday->format('Y-m-d')]);
        }

        return $issues;
    }
}
