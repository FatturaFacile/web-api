<?php

declare(strict_types=1);

namespace Modules\Returns\Support;

use RoundingMode;

/**
 * Exact arithmetic on decimal strings for quantities and amounts (bcmath, scale 10 while computing, results rounded half
 * away from zero). Floats never enter a calculation.
 */
final class Decimal
{
    private const int Scale = 10;

    public static function of(string|int|float|null $value): string
    {
        $text = trim((string) $value);

        return bcadd(is_numeric($text) ? $text : '0', '0', self::Scale);
    }

    public static function add(string|int|float|null $left, string|int|float|null $right): string
    {
        return bcadd(self::of($left), self::of($right), self::Scale);
    }

    public static function sub(string|int|float|null $left, string|int|float|null $right): string
    {
        return bcsub(self::of($left), self::of($right), self::Scale);
    }

    public static function mul(string|int|float|null $left, string|int|float|null $right): string
    {
        return bcmul(self::of($left), self::of($right), self::Scale);
    }

    /**
     * Zero when the divisor is zero: callers decide whether a missing denominator means "no figure".
     */
    public static function div(string|int|float|null $dividend, string|int|float|null $divisor): string
    {
        return bccomp(self::of($divisor), '0', self::Scale) === 0 ? self::of('0') : bcdiv(self::of($dividend), self::of($divisor), self::Scale);
    }

    public static function compare(string|int|float|null $left, string|int|float|null $right): int
    {
        return bccomp(self::of($left), self::of($right), self::Scale);
    }

    public static function isPositive(string|int|float|null $value): bool
    {
        return self::compare($value, '0') > 0;
    }

    public static function round(string|int|float|null $value, int $scale): string
    {
        return bcadd(bcround(self::of($value), $scale, RoundingMode::HalfAwayFromZero), '0', $scale);
    }
}
