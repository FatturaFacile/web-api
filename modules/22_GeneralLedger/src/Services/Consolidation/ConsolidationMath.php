<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Consolidation;

use RoundingMode;

/**
 * Decimal-string arithmetic of the consolidation at the 4 decimals of the ledger amounts. Products are rounded half
 * away from zero, like the translation of the existing statements (LedgerReportService).
 */
final class ConsolidationMath
{
    public const int Scale = 4;

    public static function zero(): string
    {
        return '0.0000';
    }

    public static function norm(string $value): string
    {
        return bcadd($value, '0', self::Scale);
    }

    public static function add(string $left, string $right): string
    {
        return bcadd($left, $right, self::Scale);
    }

    public static function sub(string $left, string $right): string
    {
        return bcsub($left, $right, self::Scale);
    }

    public static function neg(string $value): string
    {
        return bcmul($value, '-1', self::Scale);
    }

    public static function abs(string $value): string
    {
        return bccomp($value, '0', self::Scale) < 0 ? self::neg($value) : self::norm($value);
    }

    public static function mul(string $amount, string $factor): string
    {
        return bcadd(bcround(bcmul($amount, $factor, 10), self::Scale, RoundingMode::HalfAwayFromZero), '0', self::Scale);
    }

    /**
     * A share of an amount: the percentage is on a 0 to 100 scale.
     */
    public static function percentOf(string $amount, string $percentage): string
    {
        return self::mul($amount, bcdiv($percentage, '100', 10));
    }

    public static function cmp(string $left, string $right): int
    {
        return bccomp($left, $right, self::Scale);
    }

    public static function isZero(string $value): bool
    {
        return self::cmp($value, '0') === 0;
    }

    public static function isPositive(string $value): bool
    {
        return self::cmp($value, '0') > 0;
    }

    public static function min(string $left, string $right): string
    {
        return self::cmp($left, $right) <= 0 ? self::norm($left) : self::norm($right);
    }
}
