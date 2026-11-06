<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Swiss;

use DateTimeImmutable;

/**
 * The monthly family allowances of a canton from a date.
 */
final readonly class FamilyAllowanceRateData
{
    public function __construct(
        public string $canton,
        public DateTimeImmutable $validFrom,
        public string $childAllowance,
        public string $educationAllowance,
    ) {}
}
