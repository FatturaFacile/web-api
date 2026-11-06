<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\HumanResources\Models\TimeClockEvent;
use Modules\HumanResources\Models\TimeDailyRecord;
use Modules\HumanResources\Models\TimePublicHoliday;
use Modules\HumanResources\Models\TimeRecordingProfile;
use Modules\HumanResources\Services\TimeTracking\AbsenceEntry;
use Modules\HumanResources\Services\TimeTracking\ClockEntry;
use Modules\HumanResources\Services\TimeTracking\DailyEntry;
use Modules\HumanResources\Services\TimeTracking\EmployeeTimeProfile;
use Modules\HumanResources\Services\TimeTracking\HolidayEntry;
use Modules\HumanResources\Services\TimeTracking\SwissHolidayCalendar;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeEngine;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeRules;
use Modules\HumanResources\Services\TimeTracking\WorkingTimeContext;
use Modules\HumanResources\Services\TimeTracking\WorkingTimeReport;

/**
 * Loads the working time data of an employee and evaluates it with the engine.
 */
final class WorkingTimeCalculator
{
    /**
     * Evaluate the working time of an employee for a period of local days.
     */
    public function evaluate(User $employee, string $from, string $to): WorkingTimeReport
    {
        $firstDay = SwissWorkingTimeRules::localDate($from);
        $lastDay = SwissWorkingTimeRules::localDate($to);
        $yearStart = $firstDay->startOfYear();

        $context = $this->load($employee, $yearStart, $lastDay);
        $before = 0;

        if ($firstDay > $yearStart) {
            $context->from = $yearStart;
            $context->to = $firstDay->subDay();
            $before = SwissWorkingTimeEngine::evaluate($context)->statutoryOvertimeMinutes();
        }

        $context->from = $firstDay;
        $context->to = $lastDay;
        $context->statutoryOvertimeBeforeMinutes = $before;

        return SwissWorkingTimeEngine::evaluate($context);
    }

    /**
     * The holidays of a year for a canton: the cantonal calendar, overridden day by day by the generic rows of
     * time_public_holidays and then by those of the company.
     *
     * @return array<int, HolidayEntry>
     */
    public function holidays(?string $canton, int $year): array
    {
        $code = $canton === null || $canton === '' ? SwissHolidayCalendar::DEFAULT_CANTON : $canton;
        $holidays = [];

        foreach (SwissHolidayCalendar::forCanton($code, $year) as $holiday) {
            $holidays[$holiday->date] = $holiday;
        }

        TimePublicHoliday::query()
            ->whereDate('holiday_date', '>=', sprintf('%04d-01-01', $year))
            ->whereDate('holiday_date', '<=', sprintf('%04d-12-31', $year))
            ->where(fn ($query) => $query->whereNull('canton')->orWhere('canton', $code))
            ->get()
            ->sortBy(fn (TimePublicHoliday $holiday): array => [$holiday->tenant_id !== null, $holiday->canton !== null])
            ->each(function (TimePublicHoliday $holiday) use (&$holidays): void {
                $date = $holiday->holiday_date->format('Y-m-d');
                $holidays[$date] = new HolidayEntry($date, $holiday->name, $holiday->day_fraction);
            });

        ksort($holidays);

        return array_values($holidays);
    }

    private function load(User $employee, CarbonImmutable $from, CarbonImmutable $to): WorkingTimeContext
    {
        $hrEmployees = Employee::query()->where('user_id', $employee->id)->get();
        $hrEmployee = $hrEmployees->first();
        $profile = TimeRecordingProfile::query()->where('user_id', $employee->id)->first() ?? new TimeRecordingProfile;

        return new WorkingTimeContext(
            profile: new EmployeeTimeProfile(
                employeeId: (string) $employee->id,
                employeeName: (string) $employee->name,
                mode: $profile->mode,
                legalMaxWeeklyHours: $profile->legal_max_weekly_hours,
                weeklyHours: $profile->weekly_hours,
                workDaysPerWeek: $profile->work_days_per_week,
                hireDate: ($profile->hire_date ?? $hrEmployee?->hire_date)?->format('Y-m-d'),
                terminationDate: ($profile->termination_date ?? $hrEmployee?->termination_date)?->format('Y-m-d'),
                agreementDate: $profile->agreement_date?->format('Y-m-d'),
            ),
            from: $from,
            to: $to,
            now: CarbonImmutable::now(SwissWorkingTimeRules::TIMEZONE),
            events: $this->events((string) $employee->id, $from, $to),
            dailyRecords: $this->dailyRecords((string) $employee->id, $from, $to),
            absences: $this->absences($hrEmployees->pluck('id')->all(), $from, $to),
            holidays: $this->holidaysBetween($profile->canton, $from->year, $to->year),
        );
    }

    /**
     * The valid events of the period, from the day before (for the daily rest) to the day after (for a shift that
     * ends after midnight).
     *
     * @return array<int, ClockEntry>
     */
    private function events(string $employeeId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return TimeClockEvent::query()
            ->where('user_id', $employeeId)
            ->betweenLocalDays($from->subDay()->format('Y-m-d'), $to->addDay()->format('Y-m-d'))
            ->orderBy('occurred_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (TimeClockEvent $event): ClockEntry => new ClockEntry($event->occurred_at, $event->event_type))
            ->all();
    }

    /**
     * @return array<int, DailyEntry>
     */
    private function dailyRecords(string $employeeId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return TimeDailyRecord::query()
            ->where('user_id', $employeeId)
            ->whereDate('work_date', '>=', $from->format('Y-m-d'))
            ->whereDate('work_date', '<=', $to->format('Y-m-d'))
            ->get()
            ->map(fn (TimeDailyRecord $record): DailyEntry => new DailyEntry(
                $record->work_date->format('Y-m-d'),
                $record->worked_minutes,
                $record->start_time,
                $record->end_time,
            ))
            ->all();
    }

    /**
     * The approved leave requests: hr_leave_requests has no percentage, so each one covers its days entirely.
     *
     * @param  array<int, string>  $hrEmployeeIds
     * @return array<int, AbsenceEntry>
     */
    private function absences(array $hrEmployeeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return LeaveRequest::query()
            ->with('leaveType')
            ->whereIn('employee_id', $hrEmployeeIds)
            ->where('status', 'approved')
            ->whereDate('end_date', '>=', $from->format('Y-m-d'))
            ->whereDate('start_date', '<=', $to->format('Y-m-d'))
            ->orderBy('start_date')
            ->get()
            ->map(fn (LeaveRequest $leave): AbsenceEntry => new AbsenceEntry(
                absenceType: (string) ($leave->leaveType?->category ?? ''),
                startDate: $leave->start_date->format('Y-m-d'),
                endDate: $leave->end_date->format('Y-m-d'),
                isPaid: (bool) ($leave->leaveType?->is_paid ?? false),
            ))
            ->all();
    }

    /**
     * @return array<int, HolidayEntry>
     */
    private function holidaysBetween(?string $canton, int $firstYear, int $lastYear): array
    {
        $holidays = [];

        for ($year = $firstYear; $year <= $lastYear; $year++) {
            array_push($holidays, ...$this->holidays($canton, $year));
        }

        return $holidays;
    }
}
