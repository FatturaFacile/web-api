<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Italian;

/**
 * What the Italian payroll needs of a payslip of the same year: the bases and the amounts of its lines by code.
 */
final readonly class ItalianPreviousPayslip
{
    /**
     * @param  array<string, string>  $lineAmounts  the total amount by line code
     */
    public function __construct(
        public string $inpsBasis,
        public string $taxableIncome,
        public array $lineAmounts = [],
    ) {}
}
