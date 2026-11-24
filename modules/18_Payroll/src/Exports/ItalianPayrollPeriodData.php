<?php

declare(strict_types=1);

namespace Modules\Payroll\Exports;

use Illuminate\Support\Collection;
use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\ItalianPayrollSettings;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\PayslipLine;

/**
 * Payslips, lines and employees of a period, used by the Italian filings (F24, UniEmens, CU, LUL). As on the desktop
 * it reads every payslip of the period, closed or not.
 */
final class ItalianPayrollPeriodData
{
    /**
     * @param  Collection<int, Payslip>  $payslips
     * @param  Collection<int, PayslipLine>  $lines
     * @param  Collection<string, Employee>  $employees
     * @param  Collection<string, PayrollEmployeeProfile>  $profiles
     */
    private function __construct(
        public readonly Collection $payslips,
        public readonly Collection $lines,
        public readonly Collection $employees,
        public readonly Collection $profiles,
        public readonly ?ItalianPayrollSettings $settings,
    ) {}

    /**
     * Loads the data of a year, or of one month when given.
     *
     * @throws PayrollException there is no calculated payroll in the period
     */
    public static function load(int $year, ?int $month = null): self
    {
        $payslips = Payslip::query()
            ->where('year', $year)
            ->when($month !== null, static fn ($query) => $query->where('month', $month))
            ->orderBy('employee_name')
            ->orderBy('month')
            ->get();

        if ($payslips->isEmpty()) {
            throw new PayrollException('Nessuna busta paga elaborata nel periodo.');
        }

        $employeeIds = $payslips->pluck('employee_id')->unique()->all();

        return new self(
            payslips: $payslips,
            lines: PayslipLine::query()->whereIn('payslip_id', $payslips->pluck('id'))->orderBy('sort_order')->get(),
            employees: Employee::query()->withTrashed()->whereIn('id', $employeeIds)->get()->keyBy('id'),
            profiles: PayrollEmployeeProfile::query()->whereIn('employee_id', $employeeIds)->get()->keyBy('employee_id'),
            settings: ItalianPayrollSettings::query()->first(),
        );
    }

    /**
     * Sums the lines with a code, optionally for one employee.
     */
    public function added(string $code, ?string $employeeId = null): string
    {
        $payslipIds = $this->payslips
            ->filter(static fn (Payslip $payslip): bool => $employeeId === null || $payslip->employee_id === $employeeId)
            ->pluck('id')
            ->all();

        return Dec::round(Dec::sum($this->lines
            ->filter(static fn (PayslipLine $line): bool => $line->wage_type_code === $code && in_array($line->payslip_id, $payslipIds, true))
            ->map(static fn (PayslipLine $line): string => (string) $line->amount)));
    }

    /**
     * Sums the deduction lines with a code as positive amounts (a refund is negative).
     */
    public function deducted(string $code, ?string $employeeId = null): string
    {
        return Dec::round(Dec::neg($this->added($code, $employeeId)));
    }

    public function employee(string $employeeId): ?Employee
    {
        return $this->employees->get($employeeId);
    }

    public function profile(string $employeeId): ?PayrollEmployeeProfile
    {
        return $this->profiles->get($employeeId);
    }

    /**
     * The tax code of an employee: the encrypted number of the payroll profile, or what HR holds.
     */
    public function taxCode(string $employeeId): ?string
    {
        $employee = $this->employee($employeeId);

        return $this->profile($employeeId)?->social_security_number ?? $employee?->tax_code ?? $employee?->social_security_number;
    }

    /**
     * @return array<string, Collection<int, Payslip>>
     */
    public function payslipsByEmployee(): array
    {
        return $this->payslips->groupBy('employee_id')->all();
    }
}
