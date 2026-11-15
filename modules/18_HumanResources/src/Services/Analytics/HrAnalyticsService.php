<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\Analytics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\HumanResources\Exceptions\HumanResourcesException;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\EmploymentContract;
use Modules\HumanResources\Models\ExitInterview;
use Modules\HumanResources\Models\JobPosting;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\HumanResources\Models\LeaveType;
use Modules\HumanResources\Models\Position;

/**
 * Aggregates of the workforce: headcount, turnover, tenure, absenteeism, open positions and what expires soon. The
 * figures are computed in PHP from plain query builder reads, so they do not depend on the database engine, and every
 * read is limited to the active company by the models.
 *
 * An employee counts on a day when hired on or before it and not terminated before it. Candidates never count.
 */
final class HrAnalyticsService
{
    /** Leave categories that are planned time off and do not count as absenteeism. */
    private const array PlannedLeaveCategories = ['vacation', 'rol', 'study'];

    private const int MaxPeriods = 240;

    /**
     * Headcount on a day, by department, contract type and employment type, and month by month over a range.
     *
     * @return array<string, mixed>
     */
    public function headcount(Carbon $from, Carbon $to, Carbon $asOf): array
    {
        $employees = $this->employees();
        $present = $employees->filter(fn (Employee $employee): bool => $this->isEmployedOn($employee, $asOf));
        $contractTypes = EmploymentContract::query()->where('is_active', true)->pluck('contract_type', 'employee_id');
        $departments = Department::query()->pluck('name', 'id');

        $series = [];
        foreach ($this->periods($from, $to, 'month') as $period) {
            $series[] = [
                'period' => $period['label'],
                'date' => $period['end']->format('Y-m-d'),
                'headcount' => $employees->filter(fn (Employee $employee): bool => $this->isEmployedOn($employee, $period['end']))->count(),
            ];
        }

        return [
            'as_of' => $asOf->format('Y-m-d'),
            'total' => $present->count(),
            'by_department' => $present->groupBy(fn (Employee $employee): string => (string) ($employee->department_id ?? ''))
                ->map(fn (Collection $group, string $id): array => [
                    'department_id' => $id === '' ? null : $id,
                    'department_name' => $id === '' ? null : ($departments[$id] ?? null),
                    'headcount' => $group->count(),
                ])->sortByDesc('headcount')->values()->all(),
            'by_contract_type' => $this->count($present, fn (Employee $employee): string => (string) ($contractTypes[$employee->id] ?? 'unknown'), 'contract_type'),
            'by_employment_type' => $this->count($present, fn (Employee $employee): string => (string) ($employee->employment_type ?? 'unknown'), 'employment_type'),
            'series' => $series,
        ];
    }

    /**
     * Hires, terminations and turnover rate per period (month, quarter or year). The rate is the terminations of the
     * period over the average of the headcount at its start and at its end.
     *
     * @return array<string, mixed>
     */
    public function turnover(Carbon $from, Carbon $to, string $granularity): array
    {
        $employees = $this->employees();
        $periods = [];

        foreach ($this->periods($from, $to, $granularity) as $period) {
            $periods[] = $this->turnoverFigures($employees, $period['label'], $period['start'], $period['end']);
        }

        $leavers = $employees->filter(static fn (Employee $employee): bool => $employee->termination_date !== null
            && $employee->termination_date->between($from->copy()->startOfDay(), $to->copy()->endOfDay()));
        $reasons = ExitInterview::query()->whereIn('employee_id', $leavers->pluck('id')->all())->pluck('reason_code')->countBy()->all();

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'granularity' => $granularity,
            'periods' => $periods,
            'totals' => $this->turnoverFigures($employees, 'total', $from, $to),
            'by_reason' => collect($reasons)->map(static fn (int $count, string $code): array => ['reason_code' => $code, 'count' => $count])->sortByDesc('count')->values()->all(),
            'leavers_without_exit_interview' => max(0, $leavers->count() - array_sum($reasons)),
        ];
    }

    /**
     * Average tenure in years of the employees present on the day, of the employees who left, and the distribution.
     *
     * @return array<string, mixed>
     */
    public function tenure(Carbon $asOf): array
    {
        $employees = $this->employees();
        $present = $employees->filter(fn (Employee $employee): bool => $this->isEmployedOn($employee, $asOf));
        $left = $employees->filter(static fn (Employee $employee): bool => $employee->termination_date !== null && $employee->termination_date->lt($asOf->copy()->startOfDay()));
        $departments = Department::query()->pluck('name', 'id');

        $years = static fn (Employee $employee, Carbon $until): float => max(0.0, (float) $employee->hire_date->diffInDays($until, true) / 365.25);
        $average = static fn (Collection $group): ?float => $group->isEmpty() ? null : round($group->avg(), 2);

        $buckets = ['lt_1' => 0, 'from_1_to_3' => 0, 'from_3_to_5' => 0, 'from_5_to_10' => 0, 'gte_10' => 0];
        foreach ($present as $employee) {
            $tenure = $years($employee, $asOf);
            $key = match (true) {
                $tenure < 1 => 'lt_1',
                $tenure < 3 => 'from_1_to_3',
                $tenure < 5 => 'from_3_to_5',
                $tenure < 10 => 'from_5_to_10',
                default => 'gte_10',
            };
            $buckets[$key]++;
        }

        return [
            'as_of' => $asOf->format('Y-m-d'),
            'employees' => $present->count(),
            'average_tenure_years' => $average($present->map(static fn (Employee $employee): float => $years($employee, $asOf))),
            'average_tenure_leavers_years' => $average($left->map(static fn (Employee $employee): float => $years($employee, $employee->termination_date))),
            'distribution' => collect($buckets)->map(static fn (int $count, string $bucket): array => ['bucket' => $bucket, 'employees' => $count])->values()->all(),
            'by_department' => $present->groupBy(fn (Employee $employee): string => (string) ($employee->department_id ?? ''))
                ->map(fn (Collection $group, string $id): array => [
                    'department_id' => $id === '' ? null : $id,
                    'department_name' => $id === '' ? null : ($departments[$id] ?? null),
                    'average_tenure_years' => $average($group->map(static fn (Employee $employee): float => $years($employee, $asOf))),
                ])->values()->all(),
        ];
    }

    /**
     * Absence days of the approved leave requests in the range over the working days the employees were employed
     * (Monday to Friday). Planned time off (vacation, ROL, study) is reported but kept out of the absenteeism rate.
     *
     * @return array<string, mixed>
     */
    public function absenteeism(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $employees = $this->employees();
        $categories = LeaveType::query()->pluck('category', 'id');
        $departments = Department::query()->pluck('name', 'id');
        $departmentOf = $employees->pluck('department_id', 'id');

        $scheduledDays = 0;
        $scheduledByDepartment = [];
        foreach ($employees as $employee) {
            $start = $employee->hire_date->copy()->startOfDay()->max($from);
            $end = ($employee->termination_date?->copy()->startOfDay() ?? $to)->min($to);
            $days = $end->lt($start) ? 0 : $this->weekdays($start, $end);
            $scheduledDays += $days;
            $key = (string) ($employee->department_id ?? '');
            $scheduledByDepartment[$key] = ($scheduledByDepartment[$key] ?? 0) + $days;
        }

        $byCategory = [];
        $absenceByDepartment = [];
        $requests = LeaveRequest::query()
            ->where('status', 'approved')
            ->where('start_date', '<=', $to->format('Y-m-d'))
            ->where('end_date', '>=', $from->format('Y-m-d'))
            ->get(['employee_id', 'leave_type_id', 'start_date', 'end_date', 'total_days']);

        foreach ($requests as $request) {
            $start = Carbon::parse($request->start_date)->startOfDay();
            $end = Carbon::parse($request->end_date)->startOfDay();
            $length = max(1, (int) $start->diffInDays($end, true) + 1);
            $overlap = (int) $start->max($from)->diffInDays($end->min($to), true) + 1;
            $days = (float) $request->total_days * min(1.0, $overlap / $length);
            $category = (string) ($categories[$request->leave_type_id] ?? 'other');
            $byCategory[$category] = ($byCategory[$category] ?? 0.0) + $days;

            if (! in_array($category, self::PlannedLeaveCategories, true)) {
                $key = (string) ($departmentOf[$request->employee_id] ?? '');
                $absenceByDepartment[$key] = ($absenceByDepartment[$key] ?? 0.0) + $days;
            }
        }

        $rate = static fn (float $days, int $scheduled): ?float => $scheduled > 0 ? round($days / $scheduled * 100, 2) : null;
        $absenceDays = array_sum(array_diff_key($byCategory, array_flip(self::PlannedLeaveCategories)));

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'scheduled_days' => $scheduledDays,
            'absence_days' => round($absenceDays, 2),
            'planned_leave_days' => round(array_sum(array_intersect_key($byCategory, array_flip(self::PlannedLeaveCategories))), 2),
            'absenteeism_rate' => $rate($absenceDays, $scheduledDays),
            'total_leave_rate' => $rate(array_sum($byCategory), $scheduledDays),
            'by_category' => collect($byCategory)->map(static fn (float $days, string $category): array => [
                'category' => $category,
                'planned' => in_array($category, self::PlannedLeaveCategories, true),
                'days' => round($days, 2),
            ])->sortByDesc('days')->values()->all(),
            'by_department' => collect($scheduledByDepartment)->map(fn (int $scheduled, string $id): array => [
                'department_id' => $id === '' ? null : $id,
                'department_name' => $id === '' ? null : ($departments[$id] ?? null),
                'absence_days' => round($absenceByDepartment[$id] ?? 0.0, 2),
                'scheduled_days' => $scheduled,
                'absenteeism_rate' => $rate($absenceByDepartment[$id] ?? 0.0, $scheduled),
            ])->values()->all(),
        ];
    }

    /**
     * Budgeted headcount of the active positions against the employees who fill them, and the open job postings.
     *
     * @return array<string, mixed>
     */
    public function positions(Carbon $asOf): array
    {
        $filled = $this->employees()
            ->filter(fn (Employee $employee): bool => $employee->position_id !== null && $this->isEmployedOn($employee, $asOf))
            ->countBy('position_id');
        $postings = JobPosting::query()->where('status', JobPosting::StatusOpen)->whereNotNull('position_id')->pluck('position_id')->countBy();
        $openPostings = JobPosting::query()->where('status', JobPosting::StatusOpen)->count();

        $rows = Position::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'title', 'department_id', 'headcount_budget'])
            ->map(static fn (Position $position): array => [
                'position_id' => $position->id,
                'code' => $position->code,
                'title' => $position->title,
                'department_id' => $position->department_id,
                'budget' => (int) $position->headcount_budget,
                'filled' => (int) ($filled[$position->id] ?? 0),
                'open' => max(0, (int) $position->headcount_budget - (int) ($filled[$position->id] ?? 0)),
                'open_postings' => (int) ($postings[$position->id] ?? 0),
            ]);

        return [
            'as_of' => $asOf->format('Y-m-d'),
            'budget' => (int) $rows->sum('budget'),
            'filled' => (int) $rows->sum('filled'),
            'open' => (int) $rows->sum('open'),
            'open_job_postings' => $openPostings,
            'positions' => $rows->values()->all(),
        ];
    }

    /**
     * Probation periods and fixed-term contracts ending within the next days.
     *
     * @return array<string, mixed>
     */
    public function upcomingExpiries(Carbon $today, int $days): array
    {
        $until = $today->copy()->addDays($days);
        $day = static fn (?Carbon $date): ?string => $date?->format('Y-m-d');
        $remaining = static fn (Carbon $date): int => (int) $today->copy()->startOfDay()->diffInDays($date->copy()->startOfDay(), false);

        $probations = Employee::query()
            ->where('employment_status', '!=', 'terminated')
            ->whereNull('confirmation_date')
            ->whereBetween('probation_end_date', [$today->format('Y-m-d'), $until->format('Y-m-d')])
            ->orderBy('probation_end_date')
            ->get(['id', 'employee_number', 'full_name', 'department_id', 'probation_end_date'])
            ->map(static fn (Employee $employee): array => [
                'employee_id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'full_name' => $employee->full_name,
                'department_id' => $employee->department_id,
                'probation_end_date' => $day($employee->probation_end_date),
                'days_remaining' => $remaining($employee->probation_end_date),
            ]);

        $contracts = EmploymentContract::query()
            ->where('is_active', true)
            ->whereBetween('end_date', [$today->format('Y-m-d'), $until->format('Y-m-d')])
            ->orderBy('end_date')
            ->get(['id', 'employee_id', 'contract_number', 'contract_type', 'end_date']);
        $names = Employee::query()->whereIn('id', $contracts->pluck('employee_id')->all())->pluck('full_name', 'id');

        return [
            'from' => $today->format('Y-m-d'),
            'to' => $until->format('Y-m-d'),
            'days' => $days,
            'probation_ends' => $probations->values()->all(),
            'contract_expiries' => $contracts->map(static fn (EmploymentContract $contract): array => [
                'contract_id' => $contract->id,
                'employee_id' => $contract->employee_id,
                'full_name' => $names[$contract->employee_id] ?? null,
                'contract_number' => $contract->contract_number,
                'contract_type' => $contract->contract_type,
                'end_date' => $day(Carbon::parse($contract->end_date)),
                'days_remaining' => $remaining(Carbon::parse($contract->end_date)),
            ])->values()->all(),
        ];
    }

    /**
     * @return Collection<int, Employee>
     */
    private function employees(): Collection
    {
        return Employee::query()
            ->where('employment_status', '!=', 'candidate')
            ->get(['id', 'department_id', 'position_id', 'employment_type', 'employment_status', 'hire_date', 'termination_date', 'probation_end_date', 'confirmation_date']);
    }

    private function isEmployedOn(Employee $employee, Carbon $day): bool
    {
        $day = $day->copy()->startOfDay();

        return $employee->hire_date->lte($day) && ($employee->termination_date === null || $employee->termination_date->gte($day));
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return array<string, mixed>
     */
    private function turnoverFigures(Collection $employees, string $label, Carbon $start, Carbon $end): array
    {
        $startOfDay = $start->copy()->startOfDay();
        $endOfDay = $end->copy()->startOfDay();
        $opening = $employees->filter(fn (Employee $employee): bool => $this->isEmployedOn($employee, $startOfDay->copy()->subDay()))->count();
        $closing = $employees->filter(fn (Employee $employee): bool => $this->isEmployedOn($employee, $endOfDay))->count();
        $hires = $employees->filter(static fn (Employee $employee): bool => $employee->hire_date->between($startOfDay, $endOfDay))->count();
        $terminations = $employees->filter(static fn (Employee $employee): bool => $employee->termination_date !== null && $employee->termination_date->between($startOfDay, $endOfDay))->count();
        $average = ($opening + $closing) / 2;

        return [
            'period' => $label,
            'start' => $startOfDay->format('Y-m-d'),
            'end' => $endOfDay->format('Y-m-d'),
            'opening_headcount' => $opening,
            'closing_headcount' => $closing,
            'hires' => $hires,
            'terminations' => $terminations,
            'average_headcount' => round($average, 2),
            'turnover_rate' => $average > 0 ? round($terminations / $average * 100, 2) : null,
        ];
    }

    /**
     * @param  Collection<int, Employee>  $group
     * @param  callable(Employee): string  $key
     * @return list<array<string, mixed>>
     */
    private function count(Collection $group, callable $key, string $name): array
    {
        return $group->countBy($key)->sortDesc()->map(static fn (int $count, string $value): array => [$name => $value, 'headcount' => $count])->values()->all();
    }

    /**
     * Consecutive periods that cover the range; the first and the last are clipped to it.
     *
     * @return list<array{label: string, start: Carbon, end: Carbon}>
     */
    private function periods(Carbon $from, Carbon $to, string $granularity): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $periods = [];
        $cursor = match ($granularity) {
            'year' => $from->copy()->startOfYear(),
            'quarter' => $from->copy()->startOfQuarter(),
            default => $from->copy()->startOfMonth(),
        };

        while ($cursor->lte($to)) {
            $naturalEnd = match ($granularity) {
                'year' => $cursor->copy()->endOfYear()->startOfDay(),
                'quarter' => $cursor->copy()->endOfQuarter()->startOfDay(),
                default => $cursor->copy()->endOfMonth()->startOfDay(),
            };
            $label = match ($granularity) {
                'year' => $cursor->format('Y'),
                'quarter' => $cursor->format('Y').'-Q'.$cursor->quarter,
                default => $cursor->format('Y-m'),
            };
            $periods[] = ['label' => $label, 'start' => $cursor->copy()->max($from), 'end' => $naturalEnd->copy()->min($to)];

            if (count($periods) > self::MaxPeriods) {
                throw new HumanResourcesException(__('hr_analytics.range_too_long'));
            }

            $cursor = $naturalEnd->copy()->addDay();
        }

        return $periods;
    }

    /**
     * Mondays to Fridays between two days, both included.
     */
    private function weekdays(Carbon $start, Carbon $end): int
    {
        $days = (int) $start->diffInDays($end, true) + 1;
        $weekdays = intdiv($days, 7) * 5;
        $cursor = $start->copy()->addDays(intdiv($days, 7) * 7);

        while ($cursor->lte($end)) {
            if ($cursor->isWeekday()) {
                $weekdays++;
            }
            $cursor->addDay();
        }

        return $weekdays;
    }
}
