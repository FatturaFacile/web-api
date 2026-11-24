<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Swiss;

use Modules\Payroll\Calculation\AbsenceData;
use Modules\Payroll\Calculation\ChildData;
use Modules\Payroll\Calculation\EmployeeData;
use Modules\Payroll\Calculation\PayrollInputData;
use Modules\Payroll\Calculation\WageTypeData;

/**
 * Everything the calculation of one Swiss payslip needs.
 */
final readonly class SwissPayrollContext
{
    /**
     * @param  array<string, WageTypeData>  $wageTypes  the wage types of the company by code
     * @param  list<PayrollInputData>  $inputs
     * @param  list<AbsenceData>  $absences
     * @param  list<ChildData>  $children
     * @param  list<FamilyAllowanceRateData>  $familyAllowanceRates  all cantons, all validity dates
     * @param  list<WithholdingTaxTariffData>  $withholdingTaxTariffs  all validity dates
     * @param  list<string>  $parameterWarnings  warnings added to the payslip as they are
     */
    public function __construct(
        public EmployeeData $employee,
        public int $year,
        public int $month,
        public array $wageTypes,
        public SwissPayrollSettingsData $settings = new SwissPayrollSettingsData,
        public array $inputs = [],
        public array $absences = [],
        public array $children = [],
        public array $familyAllowanceRates = [],
        public array $withholdingTaxTariffs = [],
        public SwissPayrollYearToDate $yearToDate = new SwissPayrollYearToDate,
        public SwissPayrollParameters $parameters = new SwissPayrollParameters,
        public array $parameterWarnings = [],
    ) {}
}
