<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\Shifts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\HumanResources\Models\Shift;
use Modules\HumanResources\Models\StaffingNeed;
use Modules\HumanResources\Services\TimeTracking\SwissHolidayCalendar;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeRules;
use Modules\HumanResources\Services\WorkingTimeCalculator;

/**
 * The views of the plan: the board with its staffing coverage, and the absence plan.
 */
final class ShiftBoardService
{
    public function __construct(
        private readonly WorkingTimeCalculator $calculator,
        private readonly ShiftRuleValidator $validator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function board(string $from, string $to, ?string $departmentId, ?string $canton): array
    {
        $holidays = $this->holidays($canton, $from, $to);
        $days = $this->days($from, $to, $holidays);
        $shifts = Shift::query()->with('type')->betweenDays($from, $to)
            ->when($departmentId, static fn ($query) => $query->where('department_id', $departmentId))
            ->orderBy('work_date')->get();
        $employees = Employee::query()->whereIn('id', $shifts->pluck('employee_id')->unique())
            ->orWhere(static fn ($query) => $query->when($departmentId, static fn ($inner) => $inner->where('department_id', $departmentId), static fn ($inner) => $inner->whereRaw('1 = 0')))
            ->orderBy('full_name')->get();

        return [
            'from' => $from,
            'to' => $to,
            'days' => array_values($days),
            'rows' => $employees->map(static fn (Employee $employee): array => [
                'employee_id' => $employee->id,
                'employee_name' => $employee->full_name,
                'department_id' => $employee->department_id,
                'shifts' => $shifts->where('employee_id', $employee->id)->map(static fn (Shift $shift): array => self::cell($shift))->values()->all(),
            ])->values()->all(),
            'coverage' => $this->coverage($days, $shifts, $departmentId),
            'violations' => array_map(static fn (ShiftViolation $violation): array => $violation->toArray(), $this->validator->validate($from, $to, $departmentId)),
        ];
    }

    /**
     * Who is away and why: approved leave requests and published absence shifts.
     *
     * @return list<array{employee_id: string, employee_name: string, date: string, type: string, is_paid: bool, source: string}>
     */
    public function absencePlan(string $from, string $to, ?string $departmentId): array
    {
        $entries = [];
        $names = Employee::query()->withTrashed()->when($departmentId, static fn ($query) => $query->where('department_id', $departmentId))->pluck('full_name', 'id');

        $leaves = LeaveRequest::query()->with('leaveType')->whereIn('employee_id', $names->keys())->where('status', 'approved')
            ->whereDate('end_date', '>=', $from)->whereDate('start_date', '<=', $to)->get();

        foreach ($leaves as $leave) {
            $first = max($leave->start_date->format('Y-m-d'), $from);
            $last = min($leave->end_date->format('Y-m-d'), $to);

            for ($day = CarbonImmutable::parse($first); $day->format('Y-m-d') <= $last; $day = $day->addDay()) {
                $entries[] = [
                    'employee_id' => (string) $leave->employee_id,
                    'employee_name' => (string) $names->get($leave->employee_id),
                    'date' => $day->format('Y-m-d'),
                    'type' => (string) ($leave->leaveType?->category ?? ''),
                    'is_paid' => (bool) ($leave->leaveType?->is_paid ?? false),
                    'source' => 'leave',
                ];
            }
        }

        $shifts = Shift::query()->with('type')->published()->betweenDays($from, $to)->whereIn('employee_id', $names->keys())->get()
            ->filter(static fn (Shift $shift): bool => $shift->type?->category === ShiftCategory::Absence);

        foreach ($shifts as $shift) {
            $entries[] = [
                'employee_id' => (string) $shift->employee_id,
                'employee_name' => (string) $names->get($shift->employee_id),
                'date' => $shift->work_date->format('Y-m-d'),
                'type' => (string) $shift->type->code,
                'is_paid' => (bool) $shift->type->is_paid_absence,
                'source' => 'shift',
            ];
        }

        usort($entries, static fn (array $left, array $right): int => [$left['date'], $left['employee_name']] <=> [$right['date'], $right['employee_name']]);

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    public static function cell(Shift $shift): array
    {
        return [
            'id' => $shift->id,
            'work_date' => $shift->work_date->format('Y-m-d'),
            'shift_type_id' => $shift->shift_type_id,
            'code' => $shift->type?->code,
            'name' => $shift->type?->name,
            'category' => $shift->type?->category?->value,
            'starts_at' => $shift->starts_at === null ? null : SwissWorkingTimeRules::toLocal($shift->starts_at)->toIso8601String(),
            'ends_at' => $shift->ends_at === null ? null : SwissWorkingTimeRules::toLocal($shift->ends_at)->toIso8601String(),
            'break_minutes' => $shift->break_minutes,
            'planned_minutes' => $shift->planned_minutes,
            'status' => $shift->status,
            'source' => $shift->source,
        ];
    }

    /**
     * @param  array<string, array{date: string, day_kind: string, holiday: string|null}>  $days
     * @param  Collection<int, Shift>  $shifts
     * @return list<array{date: string, department_id: string, shift_type_id: string, required: int, assigned: int, gap: int}>
     */
    private function coverage(array $days, Collection $shifts, ?string $departmentId): array
    {
        $needs = StaffingNeed::query()->when($departmentId, static fn ($query) => $query->where('department_id', $departmentId))->get();
        $coverage = [];

        foreach ($days as $date => $day) {
            $valid = $needs->filter(static fn (StaffingNeed $need): bool => $need->day_kind === $day['day_kind']
                && $need->valid_from->format('Y-m-d') <= $date
                && ($need->valid_to === null || $need->valid_to->format('Y-m-d') >= $date));

            foreach ($valid as $need) {
                $assigned = $shifts->filter(static fn (Shift $shift): bool => $shift->work_date->format('Y-m-d') === $date
                    && $shift->department_id === $need->department_id
                    && $shift->shift_type_id === $need->shift_type_id)->count();

                $coverage[] = [
                    'date' => $date,
                    'department_id' => (string) $need->department_id,
                    'shift_type_id' => (string) $need->shift_type_id,
                    'required' => $need->required_count,
                    'assigned' => $assigned,
                    'gap' => max(0, $need->required_count - $assigned),
                ];
            }
        }

        return $coverage;
    }

    /**
     * @param  array<string, string>  $holidays
     * @return array<string, array{date: string, day_kind: string, holiday: string|null}>
     */
    private function days(string $from, string $to, array $holidays): array
    {
        $days = [];
        $last = SwissWorkingTimeRules::localDate($to);

        for ($day = SwissWorkingTimeRules::localDate($from); $day <= $last; $day = $day->addDay()) {
            $date = $day->format('Y-m-d');
            $days[$date] = [
                'date' => $date,
                'day_kind' => match (true) {
                    isset($holidays[$date]) => StaffingNeed::Holiday,
                    $day->isWeekend() => StaffingNeed::Weekend,
                    default => StaffingNeed::Weekday,
                },
                'holiday' => $holidays[$date] ?? null,
            ];
        }

        return $days;
    }

    /**
     * @return array<string, string>
     */
    private function holidays(?string $canton, string $from, string $to): array
    {
        $dates = [];

        for ($year = (int) substr($from, 0, 4); $year <= (int) substr($to, 0, 4); $year++) {
            foreach ($this->calculator->holidays($canton ?? SwissHolidayCalendar::DEFAULT_CANTON, $year) as $holiday) {
                $dates[$holiday->date] = $holiday->name;
            }
        }

        return $dates;
    }
}
