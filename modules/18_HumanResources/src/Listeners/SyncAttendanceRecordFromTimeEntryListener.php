<?php

declare(strict_types=1);

namespace Modules\HumanResources\Listeners;

use App\Events\AttendanceShiftChanged;
use App\Models\TimeEntry;
use Carbon\Carbon;
use Modules\HumanResources\Models\AttendanceRecord;
use Modules\HumanResources\Models\Employee;

final readonly class SyncAttendanceRecordFromTimeEntryListener
{
    public function handle(AttendanceShiftChanged $event): void
    {
        $employees = Employee::where('tenant_id', $event->tenantId)
            ->where('user_id', $event->userId)
            ->limit(2)
            ->get();

        $employee = $employees->first();
        if ($employees->count() !== 1 || ! $employee instanceof Employee) {
            return;
        }

        foreach (array_unique($event->workDates) as $workDate) {
            $this->syncWorkDate($event, $employee, $workDate);
        }
    }

    private function syncWorkDate(AttendanceShiftChanged $event, Employee $employee, string $workDate): void
    {
        $dayStart = Carbon::parse($workDate)->startOfDay();
        $dayEnd = $dayStart->copy()->addDay();
        $entries = TimeEntry::where('tenant_id', $event->tenantId)
            ->where('user_id', $event->userId)
            ->effectivePeriod($dayStart, $dayEnd)
            ->whereRaw('COALESCE(corrected_clock_out, clock_out) IS NOT NULL')
            ->orderByRaw('COALESCE(corrected_clock_in, clock_in)')
            ->get();

        $attendance = AttendanceRecord::withTrashed()
            ->where('tenant_id', $event->tenantId)
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate)
            ->lockForUpdate()
            ->first();

        if ($entries->isEmpty()) {
            if ($attendance !== null && $attendance->source === 'time_attendance_api' && ! $attendance->trashed()) {
                $attendance->delete();
            }

            return;
        }

        if ($attendance !== null && $attendance->source !== 'time_attendance_api') {
            return;
        }

        $firstEntry = $entries->first();
        $clockIn = $firstEntry->effectiveClockIn();
        $clockOut = $firstEntry->effectiveClockOut();
        $totalBreakMinutes = $entries->sum(fn (TimeEntry $entry): int => $entry->effectiveBreakMinutes());
        $totalWorkedSeconds = 0.0;

        foreach ($entries as $entry) {
            $entryClockIn = $entry->effectiveClockIn();
            $entryClockOut = $entry->effectiveClockOut();

            if ($entryClockIn->lessThan($clockIn)) {
                $clockIn = $entryClockIn;
            }
            if ($entryClockOut !== null && ($clockOut === null || $entryClockOut->greaterThan($clockOut))) {
                $clockOut = $entryClockOut;
            }

            if ($entryClockOut !== null) {
                $totalWorkedSeconds += max(
                    0.0,
                    $entryClockIn->diffInSeconds($entryClockOut) - ($entry->effectiveBreakMinutes() * 60)
                );
            }
        }

        if ($attendance === null) {
            $attendance = new AttendanceRecord;
            $attendance->tenant_id = $event->tenantId;
            $attendance->company_id = $employee->company_id ?? $event->tenantId;
            $attendance->employee_id = $employee->id;
            $attendance->work_date = $workDate;
        } elseif ($attendance->trashed()) {
            $attendance->restore();
        }

        $attendance->clock_in_at = $clockIn;
        $attendance->clock_out_at = $clockOut;
        $attendance->break_minutes = $totalBreakMinutes;
        $attendance->recorded_hours = round($totalWorkedSeconds / 3600, 2);
        $attendance->regular_hours = 0;
        $attendance->overtime_hours = 0;
        $attendance->status = 'present';
        $attendance->source = 'time_attendance_api';
        $attendance->save();
    }
}
