<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation;

use DateTimeImmutable;

/**
 * A child of the employee, for the family allowances.
 */
final readonly class ChildData
{
    public function __construct(
        public DateTimeImmutable $dateOfBirth,
        public bool $inEducation = false,
        public ?DateTimeImmutable $educationEndDate = null,
        public bool $allowanceEligible = true,
    ) {}
}
