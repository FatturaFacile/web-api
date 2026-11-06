<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Swiss;

/**
 * Cumulated values of the previous months of the same year (closed or draft runs), for the annual ceilings and the
 * 13th salary.
 */
final readonly class SwissPayrollYearToDate
{
    public function __construct(
        public string $paidDays = '0',
        public string $alvBasis = '0',
        public string $uvgBasis = '0',
    ) {}
}
