<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation;

/**
 * The kinds of wage types.
 */
final class WageTypeKind
{
    /** Cash earning, part of the gross salary. */
    public const string Earning = 'Earning';

    /** Benefit in kind: part of the gross salary and of the bases, deducted again from the payout. */
    public const string NonCash = 'NonCash';

    /** Expense allowance: paid out, not part of the gross salary. */
    public const string Expense = 'Expense';

    /** Employee deduction. */
    public const string Deduction = 'Deduction';

    /** Employer contribution (not paid out, part of the employer cost). */
    public const string Employer = 'Employer';

    /** Information line (bases). */
    public const string Information = 'Information';

    /** @var list<string> */
    public const array All = [self::Earning, self::NonCash, self::Expense, self::Deduction, self::Employer, self::Information];

    public static function isGross(?string $kind): bool
    {
        return $kind === self::Earning || $kind === self::NonCash;
    }

    public static function isContribution(?string $kind): bool
    {
        return $kind === self::Deduction || $kind === self::Employer;
    }
}
