<?php

declare(strict_types=1);

namespace Modules\Payroll\Services\Extras;

use Illuminate\Support\Collection;
use Modules\HumanResources\Models\BusinessUnit;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Services\WorkingTimeCalculator;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\PayrollDates;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\Payslip;

/**
 * Hours and labour cost of closed runs by department or site. A site is a business unit of the organisation. Hours are the
 * recorded real minutes; an employee without a user has no records, and the report says how many.
 */
final class LabourCostReportService
{
    public function __construct(private readonly WorkingTimeCalculator $calculator) {}

    /**
     * @return array{year: int, month: int|null, group_by: string, rows: list<array<string, mixed>>, employees_without_hours: int}
     */
    public function report(int $year, ?int $month, string $groupBy): array
    {
        $runs = PayrollRun::query()->where('year', $year)->where('status', PayrollRun::StatusFinal)
            ->when($month !== null, static fn ($query) => $query->where('month', $month))->pluck('id');
        $payslips = Payslip::query()->whereIn('payroll_run_id', $runs)->get();
        $employees = Employee::query()->withTrashed()->with('user')->whereIn('id', $payslips->pluck('employee_id'))->get()->keyBy('id');
        $column = $groupBy === 'site' ? 'business_unit_id' : 'department_id';
        $names = $groupBy === 'site' ? BusinessUnit::query()->pluck('name', 'id') : Department::query()->pluck('name', 'id');
        $withoutHours = 0;
        $rows = [];

        foreach ($payslips->groupBy(static fn (Payslip $payslip): string => (string) $employees->get($payslip->employee_id)?->{$column}) as $groupId => $group) {
            [$hours, $missing] = $this->hours($group, $employees);
            $withoutHours += $missing;
            $gross = Dec::sum($group->map(static fn (Payslip $payslip): string => (string) $payslip->gross_salary));
            $employer = Dec::sum($group->map(static fn (Payslip $payslip): string => (string) $payslip->employer_contributions));
            $total = Dec::add($gross, $employer);

            $rows[] = [
                'group_id' => $groupId === '' ? null : $groupId,
                'group_name' => $groupId === '' ? null : $names->get($groupId),
                'headcount' => $group->pluck('employee_id')->unique()->count(),
                'hours' => Dec::round($hours),
                'gross_salary' => Dec::round($gross),
                'employer_contributions' => Dec::round($employer),
                'labour_cost' => Dec::round($total),
                'cost_per_hour' => Dec::isPositive($hours) ? Dec::round(Dec::div($total, $hours)) : null,
            ];
        }

        usort($rows, static fn (array $left, array $right): int => [$left['group_name'] ?? ''] <=> [$right['group_name'] ?? '']);

        return ['year' => $year, 'month' => $month, 'group_by' => $groupBy, 'rows' => $rows, 'employees_without_hours' => $withoutHours];
    }

    /**
     * @param  Collection<int, Payslip>  $payslips
     * @param  Collection<string, Employee>  $employees
     * @return array{0: string, 1: int}
     */
    private function hours(Collection $payslips, Collection $employees): array
    {
        $minutes = 0;
        $missing = [];

        foreach ($payslips as $payslip) {
            $user = $employees->get($payslip->employee_id)?->user;

            if ($user === null) {
                $missing[$payslip->employee_id] = true;

                continue;
            }

            $report = $this->calculator->evaluate($user, PayrollDates::monthStart($payslip->year, $payslip->month)->format('Y-m-d'), PayrollDates::monthEnd($payslip->year, $payslip->month)->format('Y-m-d'));
            $minutes += array_sum(array_map(static fn ($day): int => $day->workedMinutes, $report->days));
        }

        return [Dec::div((string) $minutes, '60'), count($missing)];
    }
}
