<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation;

/**
 * The monthly preview of the country strategies of the desktop (a quick estimate from the annual salary).
 */
final readonly class PayrollPreview
{
    /**
     * @param  array<string, string>  $deductionBreakdown
     */
    public function __construct(
        public string $countryCode,
        public string $currency,
        public string $grossMonthlySalary,
        public string $totalDeductions,
        public string $netMonthlySalary,
        public string $employerTotalCost,
        public array $deductionBreakdown,
        public string $complianceNotes,
    ) {}
}
