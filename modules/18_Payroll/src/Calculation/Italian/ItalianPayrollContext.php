<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Italian;

use Modules\Payroll\Calculation\AbsenceData;
use Modules\Payroll\Calculation\EmployeeData;
use Modules\Payroll\Calculation\PayrollInputData;

/**
 * Input of the Italian monthly payroll of one employee.
 */
final readonly class ItalianPayrollContext
{
    /**
     * @param  list<PayrollInputData>  $inputs
     * @param  list<AbsenceData>  $absences
     * @param  list<ItalianPreviousPayslip>  $yearToDate  the payslips of the same year before this month
     * @param  list<string>  $parameterWarnings  warnings added to the payslip as they are
     */
    public function __construct(
        public EmployeeData $employee,
        public int $year,
        public int $month,
        public ?ItalianPayrollSettingsData $settings = null,
        public ?CollectiveAgreementData $agreement = null,
        public ?CollectiveAgreementLevelData $level = null,
        public array $inputs = [],
        public array $absences = [],
        public array $yearToDate = [],
        public string $previousYearTaxable = '0',
        public ItalianPayrollParameters $parameters = new ItalianPayrollParameters,
        public array $parameterWarnings = [],
    ) {}
}
