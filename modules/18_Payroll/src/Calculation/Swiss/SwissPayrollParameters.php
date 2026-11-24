<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Swiss;

/**
 * Swiss statutory values, with the defaults of the desktop application (SwissPayrollRules and
 * SwissSocialInsuranceParameters): BVG limits valid from 2025, federal values of 2026. A row of
 * payroll_annual_parameters overrides them for a year.
 */
final readonly class SwissPayrollParameters
{
    public function __construct(
        public int $year = 2026,
        public string $bvgEntryThreshold = '22680',
        public string $bvgCoordinationDeduction = '26460',
        public string $bvgMaximumSalary = '90720',
        public string $bvgMinimumCoordinatedSalary = '3780',
        public string $nbuMinimumWeeklyHours = '8',
        public string $ahvEmployeeRate = '0.053',
        public string $alvEmployeeRate = '0.011',
        public string $alvMaxAnnualSalary = '148200',
        public string $ahvRetireeMonthlyAllowance = '1400',
        public int $referenceAge = 65,
        public string $lafamMinimumMonthlyIncome = '630',
    ) {}

    /**
     * @param  array<string, mixed>  $values  snake_case keys, as stored in payroll_annual_parameters
     */
    public static function fromArray(int $year, array $values): self
    {
        $defaults = new self;

        return new self(
            year: $year,
            bvgEntryThreshold: (string) ($values['bvg_entry_threshold'] ?? $defaults->bvgEntryThreshold),
            bvgCoordinationDeduction: (string) ($values['bvg_coordination_deduction'] ?? $defaults->bvgCoordinationDeduction),
            bvgMaximumSalary: (string) ($values['bvg_maximum_salary'] ?? $defaults->bvgMaximumSalary),
            bvgMinimumCoordinatedSalary: (string) ($values['bvg_minimum_coordinated_salary'] ?? $defaults->bvgMinimumCoordinatedSalary),
            nbuMinimumWeeklyHours: (string) ($values['nbu_minimum_weekly_hours'] ?? $defaults->nbuMinimumWeeklyHours),
            ahvEmployeeRate: (string) ($values['ahv_employee_rate'] ?? $defaults->ahvEmployeeRate),
            alvEmployeeRate: (string) ($values['alv_employee_rate'] ?? $defaults->alvEmployeeRate),
            alvMaxAnnualSalary: (string) ($values['alv_max_annual_salary'] ?? $defaults->alvMaxAnnualSalary),
            ahvRetireeMonthlyAllowance: (string) ($values['ahv_retiree_monthly_allowance'] ?? $defaults->ahvRetireeMonthlyAllowance),
            referenceAge: (int) ($values['reference_age'] ?? $defaults->referenceAge),
            lafamMinimumMonthlyIncome: (string) ($values['lafam_minimum_monthly_income'] ?? $defaults->lafamMinimumMonthlyIncome),
        );
    }

    /**
     * The values to store for a year.
     *
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        return [
            'bvg_entry_threshold' => $this->bvgEntryThreshold,
            'bvg_coordination_deduction' => $this->bvgCoordinationDeduction,
            'bvg_maximum_salary' => $this->bvgMaximumSalary,
            'bvg_minimum_coordinated_salary' => $this->bvgMinimumCoordinatedSalary,
            'nbu_minimum_weekly_hours' => $this->nbuMinimumWeeklyHours,
            'ahv_employee_rate' => $this->ahvEmployeeRate,
            'alv_employee_rate' => $this->alvEmployeeRate,
            'alv_max_annual_salary' => $this->alvMaxAnnualSalary,
            'ahv_retiree_monthly_allowance' => $this->ahvRetireeMonthlyAllowance,
            'reference_age' => $this->referenceAge,
            'lafam_minimum_monthly_income' => $this->lafamMinimumMonthlyIncome,
        ];
    }
}
