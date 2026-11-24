<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use Carbon\CarbonImmutable;

/**
 * The data needed to evaluate the working time of an employee.
 */
final class WorkingTimeContext
{
    /**
     * @param  array<int, ClockEntry>  $events  The clock events, including the day before the period for the daily rest.
     * @param  array<int, DailyEntry>  $dailyRecords  The daily records (simplified recording).
     * @param  array<int, AbsenceEntry>  $absences
     * @param  array<int, HolidayEntry>  $holidays
     * @param  int  $statutoryOvertimeBeforeMinutes  The statutory overtime of the same year before the period.
     * @param  CarbonImmutable  $now  The current time: an open entry of today is not reported as a missing exit.
     * @param  array<int, PlannedShiftEntry>  $plannedShifts  The published shifts of the period.
     */
    public function __construct(
        public EmployeeTimeProfile $profile,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public CarbonImmutable $now,
        public array $events = [],
        public array $dailyRecords = [],
        public array $absences = [],
        public array $holidays = [],
        public int $statutoryOvertimeBeforeMinutes = 0,
        public array $plannedShifts = [],
    ) {}
}
