<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Italian;

/**
 * Company settings of the Italian payroll.
 */
final readonly class ItalianPayrollSettingsData
{
    public function __construct(
        public string $employeeInpsRatePercent = '9.19',
        public string $employerInpsRatePercent = '29.0',
    ) {}
}
