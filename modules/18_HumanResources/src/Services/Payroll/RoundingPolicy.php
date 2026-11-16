<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\Payroll;

use DivisionByZeroError;

/**
 * Single source of the rounding rules of the Swiss payroll engine.
 *
 * - Percentages are applied on integer Rappen and rounded half away from zero to the Rappen.
 * - Every CHF deduction and employer contribution is then rounded to the nearest 0.05 CHF (5 Rappen), the
 *   smallest Swiss coin, as is customary in Swiss payroll practice.
 * - Pro-rata calculations use the 30-day month convention (360-day year) and round half away from zero.
 */
final class RoundingPolicy
{
    public const ROUNDING_UNIT_RAPPEN = 5;

    public const DAYS_PER_MONTH = 30;

    public const DAYS_PER_YEAR = 360;

    /**
     * Applies a rate expressed in parts per million to an amount in Rappen, rounded to the Rappen.
     */
    public static function applyRate(int $baseRappen, int $ratePpm): int
    {
        return self::divideRounded($baseRappen * $ratePpm, Rappen::PPM_PER_UNIT);
    }

    /**
     * Rounds an amount in Rappen to the nearest 5 Rappen (0.05 CHF).
     */
    public static function roundToFiveRappen(int $rappen): int
    {
        return self::divideRounded($rappen, self::ROUNDING_UNIT_RAPPEN) * self::ROUNDING_UNIT_RAPPEN;
    }

    /**
     * A deduction or contribution: rate applied to the base, then rounded to 0.05 CHF.
     */
    public static function contribution(int $baseRappen, int $ratePpm): int
    {
        return self::roundToFiveRappen(self::applyRate($baseRappen, $ratePpm));
    }

    /**
     * Pro-rates an annual amount to the given number of days of the 360-day year.
     */
    public static function annualToDays(int $annualRappen, int $days): int
    {
        return self::divideRounded($annualRappen * $days, self::DAYS_PER_YEAR);
    }

    /**
     * Integer division rounding half away from zero.
     */
    public static function divideRounded(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw new DivisionByZeroError('Division by zero in payroll rounding.');
        }

        $negative = ($numerator < 0) !== ($denominator < 0);
        $absNumerator = abs($numerator);
        $absDenominator = abs($denominator);
        $quotient = intdiv(2 * $absNumerator + $absDenominator, 2 * $absDenominator);

        return $negative ? -$quotient : $quotient;
    }
}
