<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\Shifts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\HumanResources\Models\Shift;
use Modules\HumanResources\Models\ShiftException;
use Modules\HumanResources\Models\ShiftSequenceAssignment;
use Modules\HumanResources\Models\ShiftType;
use Modules\HumanResources\Models\TimeRecordingProfile;
use Modules\HumanResources\Services\TimeTracking\SwissHolidayCalendar;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeRules;
use Modules\HumanResources\Services\WorkingTimeCalculator;

/**
 * Turns the sequences and their exceptions into draft shifts. Generating again replaces only what a previous generation
 * drafted: manual shifts and published shifts stay, and so does a day with an approved leave.
 */
final class ShiftPlanner
{
    public function __construct(private readonly WorkingTimeCalculator $calculator) {}

    /**
     * @param  list<string>|null  $employeeIds
     * @return int the number of shifts created
     */
    public function generate(string $from, string $to, ?array $employeeIds = null): int
    {
        return DB::transaction(function () use ($from, $to, $employeeIds): int {
            $assignments = ShiftSequenceAssignment::query()
                ->with(['sequence', 'employee'])
                ->whereDate('start_date', '<=', $to)
                ->where(static fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $from))
                ->when($employeeIds !== null, static fn ($query) => $query->whereIn('employee_id', $employeeIds))
                ->get();

            $targetIds = $employeeIds ?? $assignments->pluck('employee_id')->unique()->all();

            Shift::query()
                ->betweenDays($from, $to)
                ->where('status', Shift::StatusDraft)
                ->whereIn('source', [Shift::SourceSequence, Shift::SourceException])
                ->whereIn('employee_id', $targetIds)
                ->forceDelete();

            $types = ShiftType::query()->get()->keyBy('id');
            $exceptions = ShiftException::query()->whereDate('exception_date', '>=', $from)->whereDate('exception_date', '<=', $to)->get();
            $leaves = $this->approvedLeaveDays($targetIds, $from, $to);
            $created = 0;

            foreach ($assignments as $assignment) {
                $created += $this->generateAssignment($assignment, $types, $exceptions, $leaves, $from, $to);
            }

            return $created;
        });
    }

    /**
     * Create or replace a single shift with the values of its type.
     *
     * @param  array{employee_id: string, shift_type_id: string, work_date: string, source?: string, note?: string|null}  $values
     */
    public function place(array $values, ?Shift $shift = null): Shift
    {
        $type = ShiftType::query()->findOrFail($values['shift_type_id']);
        $employee = Employee::query()->findOrFail($values['employee_id']);
        $instants = $type->category === ShiftCategory::Absence ? null : $type->instantsOn($values['work_date']);

        $attributes = [
            'employee_id' => $employee->id,
            'department_id' => $employee->department_id,
            'shift_type_id' => $type->id,
            'work_date' => $values['work_date'],
            'starts_at' => $instants[0] ?? null,
            'ends_at' => $instants[1] ?? null,
            'break_minutes' => $type->break_minutes,
            'planned_minutes' => self::plannedMinutes($type, $instants),
            'status' => Shift::StatusDraft,
            'source' => $values['source'] ?? Shift::SourceManual,
            'note' => $values['note'] ?? null,
            'shift_publication_id' => null,
        ];

        if ($shift === null) {
            return Shift::create($attributes);
        }

        $shift->fill($attributes)->save();

        return $shift;
    }

    /**
     * The working minutes of a shift, measured between instants: a night shift across the clock change counts the 7 or 9
     * hours really worked. Absences and on-call duty plan no working time.
     *
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $instants
     */
    public static function plannedMinutes(ShiftType $type, ?array $instants): int
    {
        if ($type->category !== ShiftCategory::Working || $instants === null) {
            return 0;
        }

        return max(0, SwissWorkingTimeRules::minutesBetween($instants[0], $instants[1]) - $type->break_minutes);
    }

    /**
     * @param  Collection<string, ShiftType>  $types
     * @param  Collection<int, ShiftException>  $exceptions
     * @param  array<string, array<string, true>>  $leaves
     */
    private function generateAssignment(ShiftSequenceAssignment $assignment, Collection $types, Collection $exceptions, array $leaves, string $from, string $to): int
    {
        $sequence = $assignment->sequence;
        $employee = $assignment->employee;

        if ($sequence === null || $employee === null || ! $sequence->is_active) {
            return 0;
        }

        $start = SwissWorkingTimeRules::localDate($assignment->start_date->format('Y-m-d'));
        $first = max($start, SwissWorkingTimeRules::localDate($from));
        $last = SwissWorkingTimeRules::localDate($to);

        if ($assignment->end_date !== null) {
            $last = min($last, SwissWorkingTimeRules::localDate($assignment->end_date->format('Y-m-d')));
        }

        $holidays = $sequence->skip_holidays ? $this->holidayDates($employee, $first, $last) : [];
        $created = 0;

        for ($day = $first; $day <= $last; $day = $day->addDay()) {
            $date = $day->format('Y-m-d');
            $elapsed = (int) $start->diffInDays($day) + $assignment->offset_days;
            $step = $sequence->pattern->stepIndex($day, count($sequence->steps), $elapsed);
            $typeId = $step === null ? null : ($sequence->steps[$step] ?? null);
            $source = Shift::SourceSequence;

            if (isset($holidays[$date])) {
                $typeId = null;
            }

            $exception = self::exceptionFor($exceptions, $employee, $date);

            if ($exception !== null) {
                $typeId = $exception->shift_type_id;
                $source = Shift::SourceException;
            }

            if ($typeId === null || ! $types->has($typeId) || isset($leaves[(string) $employee->id][$date])) {
                continue;
            }

            if (Shift::query()->where('employee_id', $employee->id)->whereDate('work_date', $date)->where('shift_type_id', $typeId)->exists()) {
                continue;
            }

            $this->place(['employee_id' => (string) $employee->id, 'shift_type_id' => $typeId, 'work_date' => $date, 'source' => $source, 'note' => $exception?->reason]);
            $created++;
        }

        return $created;
    }

    /**
     * The most specific exception of a day: the employee, then the department, then the company.
     *
     * @param  Collection<int, ShiftException>  $exceptions
     */
    private static function exceptionFor(Collection $exceptions, Employee $employee, string $date): ?ShiftException
    {
        $today = $exceptions->filter(static fn (ShiftException $exception): bool => $exception->exception_date->format('Y-m-d') === $date);

        return $today->first(static fn (ShiftException $exception): bool => $exception->employee_id === $employee->id)
            ?? $today->first(static fn (ShiftException $exception): bool => $exception->employee_id === null && $exception->department_id !== null && $exception->department_id === $employee->department_id)
            ?? $today->first(static fn (ShiftException $exception): bool => $exception->employee_id === null && $exception->department_id === null);
    }

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, array<string, true>>
     */
    private function approvedLeaveDays(array $employeeIds, string $from, string $to): array
    {
        $days = [];
        $requests = LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->whereDate('end_date', '>=', $from)
            ->whereDate('start_date', '<=', $to)
            ->get();

        foreach ($requests as $request) {
            for ($day = CarbonImmutable::parse($request->start_date->format('Y-m-d')); $day->format('Y-m-d') <= $request->end_date->format('Y-m-d'); $day = $day->addDay()) {
                $days[(string) $request->employee_id][$day->format('Y-m-d')] = true;
            }
        }

        return $days;
    }

    /**
     * @return array<string, true>
     */
    private function holidayDates(Employee $employee, CarbonImmutable $first, CarbonImmutable $last): array
    {
        $canton = $employee->user_id === null ? null : TimeRecordingProfile::query()->where('user_id', $employee->user_id)->value('canton');
        $dates = [];

        for ($year = $first->year; $year <= $last->year; $year++) {
            foreach ($this->calculator->holidays($canton ?? SwissHolidayCalendar::DEFAULT_CANTON, $year) as $holiday) {
                $dates[$holiday->date] = true;
            }
        }

        return $dates;
    }
}
