<?php

declare(strict_types=1);

namespace Modules\HumanResources\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\Shift;
use Modules\HumanResources\Models\ShiftType;
use Modules\HumanResources\Services\Shifts\ShiftCategory;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeRules;
use Modules\ShiftPlanning\Domain\Events\RosterPublishedEvent;
use Modules\ShiftPlanning\Domain\Events\ShiftAssignmentFinalizedEvent;

/**
 * Time & Attendance (Mod 072) loads the expected hours of a published roster (Mod 073). The shifts of the published or
 * locked rosters are mirrored as published `roster` shifts of one dedicated shift type, so the time recording sees them as
 * planned shifts without knowing the planner. The mirror of a day is rebuilt from the roster each time (delete the mirrored
 * shifts of the days, insert the current ones), which keeps it correct when an open shift is claimed or a swap is approved.
 * Shifts planned by hand in the HR plan are never touched.
 */
final class MirrorPublishedRosterIntoTimeTracking
{
    public const string Source = 'roster';

    public const string TypeCode = 'ROSTER';

    public function handleRosterPublished(RosterPublishedEvent $event): void
    {
        $this->sync($event->tenantId, $event->startDate, $event->endDate);
    }

    public function handleAssignmentFinalized(ShiftAssignmentFinalizedEvent $event): void
    {
        $day = DB::table('roster_shift_assignments')->where('tenant_id', $event->tenantId)->where('id', $event->assignmentId)->value('shift_date');

        if ($day !== null) {
            $date = CarbonImmutable::parse((string) $day)->toDateString();
            $this->sync($event->tenantId, $date, $date);
        }
    }

    /**
     * Rebuilds the mirrored shifts of the tenant between two days (inclusive).
     */
    public function sync(string $tenantId, string $from, string $to): void
    {
        DB::transaction(function () use ($tenantId, $from, $to): void {
            Shift::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('source', self::Source)
                ->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)->forceDelete();

            $rows = DB::table('roster_shift_assignments as a')
                ->join('roster_schedule_periods as p', 'p.id', '=', 'a.roster_schedule_period_id')
                ->join('roster_shift_templates as t', 't.id', '=', 'a.shift_template_id')
                ->where('a.tenant_id', $tenantId)->whereNotNull('a.employee_id')->whereNull('a.deleted_at')->whereNull('p.deleted_at')
                ->whereIn('p.status', ['published', 'locked'])
                ->whereDate('a.shift_date', '>=', $from)->whereDate('a.shift_date', '<=', $to)
                ->get(['a.employee_id', 'a.shift_date', 'a.start_at', 'a.end_at', 't.break_minutes']);

            if ($rows->isEmpty()) {
                return;
            }

            $employees = Employee::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('id', $rows->pluck('employee_id')->unique()->all())->get()->keyBy('id');
            $type = $this->shiftType($tenantId);
            $days = [];

            foreach ($rows as $row) {
                $employee = $employees->get((string) $row->employee_id);

                if ($employee === null) {
                    continue;
                }

                $date = CarbonImmutable::parse((string) $row->shift_date)->toDateString();
                $start = CarbonImmutable::parse((string) $row->start_at, 'UTC');
                $end = CarbonImmutable::parse((string) $row->end_at, 'UTC');
                $key = $employee->id.'|'.$date;
                $minutes = max(0, SwissWorkingTimeRules::minutesBetween($start, $end) - (int) $row->break_minutes);
                $current = $days[$key] ?? ['start' => $start, 'end' => $end, 'break' => 0, 'planned' => 0];
                $days[$key] = [
                    'employee' => $employee,
                    'date' => $date,
                    'start' => $start < $current['start'] ? $start : $current['start'],
                    'end' => $end > $current['end'] ? $end : $current['end'],
                    'break' => $current['break'] + (int) $row->break_minutes,
                    'planned' => $current['planned'] + $minutes,
                ];
            }

            foreach ($days as $day) {
                Shift::withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId,
                    'employee_id' => $day['employee']->id,
                    'department_id' => $day['employee']->department_id,
                    'shift_type_id' => $type->id,
                    'work_date' => $day['date'],
                    'starts_at' => $day['start'],
                    'ends_at' => $day['end'],
                    'break_minutes' => min(65535, $day['break']),
                    'planned_minutes' => $day['planned'],
                    'status' => Shift::StatusPublished,
                    'source' => self::Source,
                    'note' => 'Mod 073 roster',
                ]);
            }
        });
    }

    private function shiftType(string $tenantId): ShiftType
    {
        return ShiftType::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('code', self::TypeCode)->whereNull('deleted_at')->first()
            ?? ShiftType::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'code' => self::TypeCode,
                'name' => 'Roster (Mod 073)',
                'category' => ShiftCategory::Working,
                'break_minutes' => 0,
                'is_paid_absence' => false,
                'is_active' => true,
            ]);
    }
}
