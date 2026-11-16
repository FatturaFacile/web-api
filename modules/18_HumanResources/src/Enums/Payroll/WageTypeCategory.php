<?php

declare(strict_types=1);

namespace Modules\HumanResources\Enums\Payroll;

/**
 * How a wage type flows into gross and net pay.
 *
 * - salary, allowance: part of gross and paid out.
 * - benefit_in_kind: part of gross (and subject to insurances) but not paid out in cash.
 * - expense: reimbursement, not part of gross, paid out with the net salary.
 * - net_deduction: deducted from the net salary (e.g. parking, canteen), not part of gross.
 */
enum WageTypeCategory: string
{
    case Salary = 'salary';
    case Allowance = 'allowance';
    case BenefitInKind = 'benefit_in_kind';
    case Expense = 'expense';
    case NetDeduction = 'net_deduction';

    public function isPartOfGross(): bool
    {
        return match ($this) {
            self::Salary, self::Allowance, self::BenefitInKind => true,
            self::Expense, self::NetDeduction => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
