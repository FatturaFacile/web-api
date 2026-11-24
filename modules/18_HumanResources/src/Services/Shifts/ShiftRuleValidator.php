<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\Shifts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\Shift;
use Modules\HumanResources\Models\TimeRecordingProfile;
use Modules\HumanResources\Services\TimeTracking\ClockEntry;
use Modules\HumanResources\Services\TimeTracking\EmployeeTimeProfile;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeEngine;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeRules;
use Modules\HumanResources\Services\TimeTracking\TimeEventType;
use Modules\HumanResources\Services\TimeTracking\TimeRecordingMode;
use Modules\HumanResources\Services\TimeTracking\WorkingDayResult;
use Modules\HumanResources\Services\TimeTracking\WorkingTimeContext;

/**
 * Checks a plan against the Swiss rest and maximum-hours rules by feeding the planned shifts to the same engine that
 * evaluates the clock events: there is a single implementation of the rules. Planned shifts are always checked as detailed
 * recording, whatever the profile of the employee, because a plan has start and end times. The break of a shift is placed
 * in its middle: the engine needs a position, the law only a duration.
 */
final class ShiftRuleValidator
{
    /**
     * @return list<ShiftViolation>
     */
    public function validate(string $from, string $to, ?string $departmentId = null): array
    {
        $violations = [];
        $firstDay = SwissWorkingTimeRules::localDate($from);
        $lastDay = SwissWorkingTimeRules::localDate($to);
        $windowStart = $firstDay->subDays(($firstDay->dayOfWeek + 6) % 7);
        $windowEnd = $lastDay->addDays(6 - (($lastDay->dayOfWeek + 6) % 7));

        $shifts = Shift::query()
            ->with('type')
            ->betweenDays($windowStart->subDay()->format('Y-m-d'), $windowEnd->format('Y-m-d'))
            ->whereNotNull('starts_at')
            ->get()
            ->filter(static fn (Shift $shift): bool => $shift->type?->category === ShiftCategory::Working)
            ->groupBy('employee_id');

        $planned = Shift::query()->betweenDays($from, $to)->when($departmentId, static fn ($query) => $query->where('department_id', $departmentId))->pluck('employee_id')->unique();
        $employees = Employee::query()->whereIn('id', $planned)->get()->keyBy('id');

        foreach ($employees as $employeeId => $employee) {
            array_push($violations, ...$this->validateEmployee($employee, $shifts->get($employeeId, collect()), $firstDay, $lastDay, $windowStart, $windowEnd));
        }

        return $violations;
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     * @return list<ShiftViolation>
     */
    private function validateEmployee(Employee $employee, Collection $shifts, CarbonImmutable $firstDay, CarbonImmutable $lastDay, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        $profile = $employee->user_id === null ? null : TimeRecordingProfile::query()->where('user_id', $employee->user_id)->first();
        $profile ??= new TimeRecordingProfile;

        $report = SwissWorkingTimeEngine::evaluate(new WorkingTimeContext(
            profile: new EmployeeTimeProfile(
                employeeId: (string) $employee->id,
                employeeName: (string) $employee->full_name,
                mode: TimeRecordingMode::Detailed,
                legalMaxWeeklyHours: $profile->legal_max_weekly_hours,
                weeklyHours: $profile->weekly_hours,
                workDaysPerWeek: $profile->work_days_per_week,
            ),
            from: $windowStart,
            to: $windowEnd,
            now: $windowEnd->addDays(2),
            events: $this->plannedEvents($shifts),
        ));

        $violations = [];

        foreach ($report->days as $day) {
            if ($day->date < $firstDay || $day->date > $lastDay) {
                continue;
            }

            foreach ($day->issues as $issue) {
                $violations[] = new ShiftViolation((string) $employee->id, (string) $employee->full_name, $day->date->format('Y-m-d'), $issue, self::isBlocking($day, $issue));
            }
        }

        foreach ($report->weeks as $week) {
            if ($week->statutoryOvertimeMinutes > 0 && $week->weekStart->addDays(6) >= $firstDay && $week->weekStart <= $lastDay) {
                $violations[] = new ShiftViolation(
                    (string) $employee->id,
                    (string) $employee->full_name,
                    $week->weekStart->format('Y-m-d'),
                    sprintf('Settimana oltre il massimo legale di %d ore (art. 9 LL): %d minuti di lavoro straordinario pianificati', intdiv($week->legalMaximumMinutes, 60), $week->statutoryOvertimeMinutes),
                    true,
                );
            }
        }

        return $violations;
    }

    /**
     * Rest below the reduced minimum, breaks and the daily span are outside the law; a rest between 8 and 11 hours and the
     * notes on night and Sunday work depend on facts the plan does not hold (the week, the permit).
     */
    private static function isBlocking(WorkingDayResult $day, string $issue): bool
    {
        $restTooShort = $day->restBeforeMinutes !== null && $day->restBeforeMinutes < SwissWorkingTimeRules::REDUCED_DAILY_REST_MINUTES;
        $breakTooShort = $day->breakMinutes < SwissWorkingTimeRules::requiredBreakMinutes($day->workedMinutes);
        $spanTooLong = $day->firstIn !== null && $day->lastOut !== null
            && SwissWorkingTimeRules::minutesBetween($day->firstIn, $day->lastOut) > SwissWorkingTimeRules::MAXIMUM_DAILY_SPAN_MINUTES;

        return match (true) {
            str_contains($issue, 'art. 15a') => $restTooShort,
            str_contains($issue, 'art. 15 ') => $breakTooShort,
            str_contains($issue, 'art. 10') => $spanTooLong,
            str_contains($issue, 'art. 12') => true,
            default => false,
        };
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     * @return list<ClockEntry>
     */
    private function plannedEvents(Collection $shifts): array
    {
        $events = [];

        foreach ($shifts as $shift) {
            $start = SwissWorkingTimeRules::toLocal($shift->starts_at);
            $end = SwissWorkingTimeRules::toLocal($shift->ends_at);
            $events[] = new ClockEntry($start, TimeEventType::In);

            if ($shift->break_minutes > 0) {
                $middle = $start->addSeconds((int) floor(($end->getTimestamp() - $start->getTimestamp() - $shift->break_minutes * 60) / 2));
                $events[] = new ClockEntry($middle, TimeEventType::BreakStart);
                $events[] = new ClockEntry($middle->addMinutes($shift->break_minutes), TimeEventType::BreakEnd);
            }

            $events[] = new ClockEntry($end, TimeEventType::Out);
        }

        return $events;
    }
}
