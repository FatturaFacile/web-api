<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation;

/**
 * The header of a calculated payslip. In Italian payrolls the AHV and withholding columns of the desktop schema hold
 * the INPS basis and the IRPEF taxable income, as they do in C#.
 */
final readonly class PayslipData
{
    public function __construct(
        public string $employeeId,
        public int $year,
        public int $month,
        public string $employeeName,
        public string $paidDays,
        public string $grossSalary,
        public string $ahvBasis,
        public string $alvBasis,
        public string $uvgBasis,
        public string $ktgBasis,
        public string $bvgInsuredSalary,
        public string $withholdingTaxBasis,
        public string $withholdingTaxRatePercent,
        public ?string $withholdingTaxCode,
        public ?string $withholdingTaxCanton,
        public string $employeeDeductions,
        public string $netSalary,
        public string $payoutAmount,
        public string $employerContributions,
        public ?string $warnings,
    ) {}
}
