<?php

declare(strict_types=1);

namespace Modules\Payroll\Services;

use Carbon\CarbonImmutable;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\Timesheet;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Exports\CsvBuilder;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\PayslipLine;

/**
 * Management views of the payroll: allocation of the labour cost of a run to projects and cost centres from the
 * approved timesheets of the month, and year-end summaries per employee from the closed runs. Both are plain data
 * exports (JSON, CSV), not official filings.
 */
final class PayrollReportService
{
    /** Timesheets in these states count for the allocation. */
    private const array CountedTimesheetStatuses = ['approved', 'posted_to_project'];

    /**
     * Allocate the cost of each payslip (gross salary plus employer contributions) to the projects and cost centres of
     * the approved timesheets of the employee in the month, in proportion to the hours; the rounding difference goes to
     * the largest share. Without timesheet hours the whole cost goes to the cost centre of the employee, or stays
     * unallocated.
     *
     * @return array{run_id: string, year: int, month: int, currency: string, lines: list<array<string, mixed>>, totals: list<array<string, mixed>>}
     */
    public function costAllocation(PayrollRun $run): array
    {
        $first = sprintf('%04d-%02d-01', $run->year, $run->month);
        $last = CarbonImmutable::parse($first)->endOfMonth()->format('Y-m-d');
        $payslips = Payslip::query()->where('payroll_run_id', $run->id)->orderBy('employee_name')->get();
        $employees = Employee::query()->whereIn('id', $payslips->pluck('employee_id'))->get(['id', 'cost_center_id'])->keyBy('id');
        $timesheets = Timesheet::query()
            ->whereIn('employee_id', $payslips->pluck('employee_id'))
            ->whereIn('status', self::CountedTimesheetStatuses)
            ->whereBetween('work_date', [$first, $last])
            ->get(['employee_id', 'project_id', 'cost_center_id', 'total_hours'])
            ->groupBy('employee_id');

        $lines = [];
        $totals = [];

        foreach ($payslips as $payslip) {
            $cost = Dec::add((string) $payslip->gross_salary, (string) $payslip->employer_contributions);
            $targets = [];

            foreach ($timesheets->get((string) $payslip->employee_id, collect()) as $timesheet) {
                [$type, $id] = $timesheet->project_id !== null ? ['project', (string) $timesheet->project_id] : ($timesheet->cost_center_id !== null ? ['cost_center', (string) $timesheet->cost_center_id] : ['unallocated', null]);
                $key = $type.':'.$id;
                $targets[$key] = ['type' => $type, 'id' => $id, 'hours' => Dec::add($targets[$key]['hours'] ?? '0', (string) $timesheet->total_hours)];
            }

            $allHours = Dec::sum(array_column($targets, 'hours'));

            if (! Dec::isPositive($allHours)) {
                $costCenter = $employees->get((string) $payslip->employee_id)?->cost_center_id;
                $targets = [['type' => $costCenter === null ? 'unallocated' : 'cost_center', 'id' => $costCenter === null ? null : (string) $costCenter, 'hours' => '0']];
            }

            foreach ($this->split($cost, array_values($targets), $allHours) as $share) {
                $lines[] = [
                    'employee_id' => $payslip->employee_id,
                    'employee_name' => $payslip->employee_name,
                    'target_type' => $share['type'],
                    'target_id' => $share['id'],
                    'hours' => Dec::round($share['hours']),
                    'amount' => $share['amount'],
                ];
                $key = $share['type'].':'.$share['id'];
                $totals[$key] = ['target_type' => $share['type'], 'target_id' => $share['id'], 'amount' => Dec::round(Dec::add($totals[$key]['amount'] ?? '0', $share['amount']))];
            }
        }

        return [
            'run_id' => (string) $run->id,
            'year' => (int) $run->year,
            'month' => (int) $run->month,
            'currency' => (string) $run->currency,
            'lines' => $lines,
            'totals' => array_values($totals),
        ];
    }

    /**
     * Year totals per employee from the closed runs of the year: gross, deductions, net, payout, employer contributions
     * and the total of every wage type code.
     *
     * @return list<array<string, mixed>>
     */
    public function yearEndSummary(int $year): array
    {
        $runIds = PayrollRun::query()->where('year', $year)->where('status', PayrollRun::StatusFinal)->pluck('id');
        $payslips = Payslip::query()->whereIn('payroll_run_id', $runIds)->orderBy('employee_name')->orderBy('month')->get();
        $lines = PayslipLine::query()->whereIn('payslip_id', $payslips->pluck('id'))->get(['payslip_id', 'wage_type_code', 'amount'])->groupBy('payslip_id');
        $summaries = [];

        foreach ($payslips as $payslip) {
            $id = (string) $payslip->employee_id;
            $summary = $summaries[$id] ?? [
                'employee_id' => $id, 'employee_name' => $payslip->employee_name, 'months' => [],
                'gross_salary' => '0', 'employee_deductions' => '0', 'net_salary' => '0', 'payout_amount' => '0', 'employer_contributions' => '0', 'amounts_by_code' => [],
            ];
            $summary['months'][] = (int) $payslip->month;

            foreach (['gross_salary', 'employee_deductions', 'net_salary', 'payout_amount', 'employer_contributions'] as $column) {
                $summary[$column] = Dec::round(Dec::add($summary[$column], (string) $payslip->{$column}));
            }

            foreach ($lines->get((string) $payslip->id, collect()) as $line) {
                $summary['amounts_by_code'][$line->wage_type_code] = Dec::round(Dec::add($summary['amounts_by_code'][$line->wage_type_code] ?? '0', (string) $line->amount));
            }

            ksort($summary['amounts_by_code']);
            $summaries[$id] = $summary;
        }

        return array_values($summaries);
    }

    /**
     * The year-end summary as CSV: one row per employee and wage type code, after the totals row of the employee.
     *
     * @param  list<array<string, mixed>>  $summaries
     */
    public function yearEndCsv(int $year, array $summaries): string
    {
        $csv = new CsvBuilder;
        $csv->row('year', 'employee_id', 'employee_name', 'wage_type_code', 'amount', 'gross_salary', 'employee_deductions', 'net_salary', 'payout_amount', 'employer_contributions', 'months');

        foreach ($summaries as $summary) {
            $csv->row($year, $summary['employee_id'], $summary['employee_name'], 'TOTAL', null, $summary['gross_salary'], $summary['employee_deductions'], $summary['net_salary'], $summary['payout_amount'], $summary['employer_contributions'], implode(',', $summary['months']));

            foreach ($summary['amounts_by_code'] as $code => $amount) {
                $csv->row($year, $summary['employee_id'], $summary['employee_name'], $code, $amount, null, null, null, null, null, null);
            }
        }

        return $csv->encoded();
    }

    /**
     * @param  list<array{type: string, id: string|null, hours: string}>  $targets
     * @return list<array{type: string, id: string|null, hours: string, amount: string}>
     */
    private function split(string $cost, array $targets, string $allHours): array
    {
        if (! Dec::isPositive($allHours)) {
            return [[...$targets[0], 'amount' => Dec::round($cost)]];
        }

        $shares = [];
        $allocated = '0';
        $largest = 0;

        foreach ($targets as $index => $target) {
            $amount = Dec::round(Dec::div(Dec::mul($cost, $target['hours']), $allHours));
            $shares[] = [...$target, 'amount' => $amount];
            $allocated = Dec::add($allocated, $amount);

            if (Dec::cmp($target['hours'], $targets[$largest]['hours']) > 0) {
                $largest = $index;
            }
        }

        $shares[$largest]['amount'] = Dec::round(Dec::add($shares[$largest]['amount'], Dec::sub(Dec::round($cost), $allocated)));

        return $shares;
    }
}
