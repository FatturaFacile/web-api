<?php

declare(strict_types=1);

namespace Modules\Payroll\Services;

use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Calculation\EmployeeData;
use Modules\Payroll\Calculation\PayrollDates;
use Modules\Payroll\Models\PayrollEmployeeProfile;

/**
 * Turns an HR employee and its payroll profile into what the engines read.
 */
final class EmployeeDataFactory
{
    public function make(Employee $employee, ?PayrollEmployeeProfile $profile): EmployeeData
    {
        return new EmployeeData(
            hireDate: PayrollDates::from($employee->hire_date),
            id: (string) $employee->id,
            fullName: (string) $employee->full_name,
            terminationDate: $employee->termination_date === null ? null : PayrollDates::from($employee->termination_date),
            dateOfBirth: $employee->birth_date === null ? null : PayrollDates::from($employee->birth_date),
            gender: $profile->gender ?? self::gender($employee->gender),
            baseAnnualSalary: (string) $employee->base_annual_salary,
            fteRatio: (string) $employee->fte_ratio,
            salaryInstallmentsPerYear: $profile->salary_installments_per_year ?? 12,
            isFixedTermContract: $profile->is_fixed_term_contract ?? false,
            payType: $profile->pay_type ?? 'monthly',
            hourlyWage: (string) ($profile->hourly_wage ?? '0'),
            weeklyHours: (string) ($profile->weekly_hours ?? '42'),
            holidayWeeks: $profile->holiday_weeks ?? 4,
            workCanton: $profile?->work_canton,
            residenceCountry: $profile->residence_country ?? 'CH',
            residenceCanton: $profile?->residence_canton,
            isWithholdingTaxSubject: $profile->is_withholding_tax_subject ?? false,
            withholdingTaxCode: $profile?->withholding_tax_code,
            withholdingTaxRatePercent: $profile?->withholding_tax_rate_percent,
            nonOccupationalAccidentRatePercent: (string) ($profile->non_occupational_accident_rate_percent ?? '0'),
            occupationalAccidentRatePercent: (string) ($profile->occupational_accident_rate_percent ?? '0'),
            dailySicknessRatePercent: (string) ($profile->daily_sickness_rate_percent ?? '0'),
            familyAllowanceFundRatePercent: (string) ($profile->family_allowance_fund_rate_percent ?? '0'),
            pensionFundEmployeeRatePercent: $profile?->pension_fund_employee_rate_percent,
            regionalSurchargeRatePercent: (string) ($profile->regional_surcharge_rate_percent ?? '0'),
            municipalSurchargeRatePercent: (string) ($profile->municipal_surcharge_rate_percent ?? '0'),
        );
    }

    /**
     * HR stores free text ("female", "F"); the engines read M or F.
     */
    private static function gender(?string $gender): ?string
    {
        return $gender === null || $gender === '' ? null : strtoupper($gender[0]);
    }
}
