<?php

declare(strict_types=1);

namespace Modules\Payroll\Services\Construction;

use Illuminate\Support\Collection;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Services\WorkingTimeCalculator;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\PayrollDates;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Exports\CsvBuilder;
use Modules\Payroll\Exports\GeneratedFile;
use Modules\Payroll\Models\CclConstructionParameter;
use Modules\Payroll\Models\CclWorkCalendar;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\Payslip;

/**
 * Construction payroll under a collective agreement: the constant monthly wage from the hourly wage and the annual hours,
 * the hours of the accounting year against the company calendar, and the yearly report for the joint commission with the
 * average pay per qualification. The figures of each agreement are its own parameters; nothing is assumed here.
 */
final class ConstructionWageService
{
    public function __construct(private readonly WorkingTimeCalculator $calculator) {}

    /**
     * The parameters of an agreement on a date: the company version before the generic one.
     */
    public function parameters(string $agreementCode, string $date): CclConstructionParameter
    {
        return CclConstructionParameter::query()
            ->where('agreement_code', $agreementCode)
            ->whereDate('valid_from', '<=', $date)
            ->orderByDesc('valid_from')
            ->orderByRaw('tenant_id IS NULL')
            ->first() ?? throw new PayrollException("Parametri del CCL {$agreementCode} mancanti: vanno inseriti con la loro fonte.");
    }

    /**
     * The hourly wage converted to a constant monthly wage: hourly wage × annual hours / 12.
     */
    public function monthlyWage(string $hourlyWage, string $annualHours): string
    {
        return Dec::round(Dec::div(Dec::mul($hourlyWage, $annualHours), '12'));
    }

    /**
     * Worked hours against the calendar, month by month, with the running balance.
     *
     * @return array{agreement_code: string, accounting_year: int, months: list<array<string, mixed>>, balance_hours: string}
     */
    public function hoursBalance(Employee $employee, string $agreementCode, int $accountingYear): array
    {
        $parameters = $this->parameters($agreementCode, sprintf('%d-12-31', $accountingYear + 1));
        $calendar = CclWorkCalendar::query()->where('agreement_code', $agreementCode)->get();
        $months = [];
        $balance = '0';

        foreach (self::accountingMonths($accountingYear, $parameters->accounting_year_start_month) as [$year, $month]) {
            $planned = (string) ($calendar->first(static fn (CclWorkCalendar $row): bool => $row->year === $year && $row->month === $month)?->planned_hours ?? '0');
            $worked = $this->workedHours($employee, $year, $month);
            $balance = Dec::add($balance, Dec::sub($worked, $planned));
            $months[] = ['year' => $year, 'month' => $month, 'planned_hours' => Dec::round($planned), 'worked_hours' => Dec::round($worked), 'balance_hours' => Dec::round($balance)];
        }

        return ['agreement_code' => $agreementCode, 'accounting_year' => $accountingYear, 'months' => $months, 'balance_hours' => Dec::round($balance)];
    }

    /**
     * The report of an accounting year: per qualification and zone, the people, their hours and pay, the average pay and
     * who is below the minimum of the agreement.
     *
     * @return array<string, mixed>
     */
    public function jointCommissionReport(string $agreementCode, int $accountingYear): array
    {
        $parameters = $this->parameters($agreementCode, sprintf('%d-12-31', $accountingYear + 1));
        $months = self::accountingMonths($accountingYear, $parameters->accounting_year_start_month);
        $profiles = PayrollEmployeeProfile::query()->with('employee')->where('collective_agreement_code', $agreementCode)->get();
        $payslips = $this->closedPayslips($months, $profiles->pluck('employee_id')->all());
        $rows = [];

        foreach ($profiles->groupBy(static fn (PayrollEmployeeProfile $profile): string => "{$profile->contract_level}|{$profile->ccl_zone}") as $group) {
            $rows[] = $this->reportRow($group, $payslips, $months, $parameters);
        }

        usort($rows, static fn (array $left, array $right): int => [$left['qualification'], $left['zone']] <=> [$right['qualification'], $right['zone']]);

        return [
            'agreement_code' => $agreementCode,
            'accounting_year' => $accountingYear,
            'parameters_valid_from' => $parameters->valid_from->format('Y-m-d'),
            'annual_hours' => (string) $parameters->annual_hours,
            'source' => $parameters->source,
            'rows' => $rows,
        ];
    }

    public function jointCommissionFile(string $agreementCode, int $accountingYear): GeneratedFile
    {
        $report = $this->jointCommissionReport($agreementCode, $accountingYear);
        $csv = new CsvBuilder;
        $csv->row('Qualifica', 'Zona', 'Persone', 'Ore lavorate', 'Salario lordo', 'Media oraria', 'Media mensile', 'Minimo orario', 'Sotto il minimo');

        foreach ($report['rows'] as $row) {
            $csv->row($row['qualification'], $row['zone'], $row['headcount'], $row['worked_hours'], $row['gross_salary'], $row['average_hourly'], $row['average_monthly'], $row['minimum_hourly'], count($row['below_minimum']));
        }

        return new GeneratedFile(
            "Commissione_paritetica_{$agreementCode}_{$accountingYear}.csv",
            $csv->encoded(),
            'Riepilogo per la commissione paritetica. Il tracciato è di questa applicazione: la commissione può chiedere il proprio modulo.',
        );
    }

    /**
     * @param  Collection<int, PayrollEmployeeProfile>  $group
     * @param  Collection<string, Collection<int, Payslip>>  $payslips
     * @param  list<array{0: int, 1: int}>  $months
     * @return array<string, mixed>
     */
    private function reportRow(Collection $group, Collection $payslips, array $months, CclConstructionParameter $parameters): array
    {
        $first = $group->first();
        $minimum = $parameters->minimum_wages[(string) $first->contract_level][(string) $first->ccl_zone] ?? null;
        $gross = '0';
        $hours = '0';
        $paidMonths = 0;
        $below = [];

        foreach ($group as $profile) {
            $slips = $payslips->get($profile->employee_id, collect());
            $employeeGross = Dec::sum($slips->map(static fn (Payslip $payslip): string => (string) $payslip->gross_salary));
            $employeeHours = '0';

            foreach ($months as [$year, $month]) {
                if ($slips->contains(static fn (Payslip $payslip): bool => $payslip->year === $year && $payslip->month === $month) && $profile->employee !== null) {
                    $employeeHours = Dec::add($employeeHours, $this->workedHours($profile->employee, $year, $month));
                }
            }

            $gross = Dec::add($gross, $employeeGross);
            $hours = Dec::add($hours, $employeeHours);
            $paidMonths += $slips->count();

            if ($minimum !== null && Dec::isPositive($employeeHours) && Dec::cmp(Dec::div($employeeGross, $employeeHours), (string) $minimum['hourly']) < 0) {
                $below[] = (string) $profile->employee_id;
            }
        }

        return [
            'qualification' => (string) $first->contract_level,
            'zone' => (string) $first->ccl_zone,
            'headcount' => $group->count(),
            'worked_hours' => Dec::round($hours),
            'gross_salary' => Dec::round($gross),
            'average_hourly' => Dec::isPositive($hours) ? Dec::round(Dec::div($gross, $hours)) : null,
            'average_monthly' => $paidMonths > 0 ? Dec::round(Dec::div($gross, (string) $paidMonths)) : null,
            'minimum_hourly' => $minimum['hourly'] ?? null,
            'minimum_monthly' => $minimum['monthly'] ?? null,
            'below_minimum' => $below,
        ];
    }

    /**
     * @param  list<array{0: int, 1: int}>  $months
     * @param  list<string>  $employeeIds
     * @return Collection<string, Collection<int, Payslip>>
     */
    private function closedPayslips(array $months, array $employeeIds): Collection
    {
        $years = array_unique(array_column($months, 0));
        $runs = PayrollRun::query()->whereIn('year', $years)->where('status', PayrollRun::StatusFinal)->get()
            ->filter(static fn (PayrollRun $run): bool => in_array([$run->year, $run->month], $months, true))
            ->pluck('id');

        return Payslip::query()->whereIn('payroll_run_id', $runs)->whereIn('employee_id', $employeeIds)->get()->groupBy('employee_id');
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private static function accountingMonths(int $accountingYear, int $startMonth): array
    {
        $months = [];

        for ($offset = 0; $offset < 12; $offset++) {
            $month = $startMonth + $offset;
            $months[] = $month > 12 ? [$accountingYear + 1, $month - 12] : [$accountingYear, $month];
        }

        return $months;
    }

    private function workedHours(Employee $employee, int $year, int $month): string
    {
        if ($employee->user === null) {
            return '0';
        }

        $report = $this->calculator->evaluate($employee->user, PayrollDates::monthStart($year, $month)->format('Y-m-d'), PayrollDates::monthEnd($year, $month)->format('Y-m-d'));

        return Dec::div((string) array_sum(array_map(static fn ($day): int => $day->workedMinutes, $report->days)), '60');
    }
}
