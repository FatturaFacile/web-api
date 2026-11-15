<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs\Payroll;

/**
 * A variable payroll input of the period (bonus, overtime, allowance, expense, net deduction...).
 * The amount is always positive; the wage type category decides how it affects gross and net pay.
 */
final readonly class EarningInput
{
    public function __construct(
        public WageTypeData $wageType,
        public int $amountRappen,
        public ?string $label = null,
    ) {}
}
