<?php

declare(strict_types=1);

namespace Modules\Sustainability\Support;

use RoundingMode;

/**
 * Exact arithmetic on decimal strings for quantities, emission factors and CO2e (bcmath, scale 10 while computing,
 * results rounded half away from zero). Floats never enter a calculation.
 */
final class Decimal
{
    private const int Scale = 10;

    public static function of(string|int|null $value): string
    {
        $text = trim((string) $value);

        return bcadd(is_numeric($text) ? $text : '0', '0', self::Scale);
    }

    public static function add(string $left, string $right): string
    {
        return bcadd(self::of($left), self::of($right), self::Scale);
    }

    public static function sub(string $left, string $right): string
    {
        return bcsub(self::of($left), self::of($right), self::Scale);
    }

    public static function mul(string $left, string $right): string
    {
        return bcmul(self::of($left), self::of($right), self::Scale);
    }

    /**
     * Zero when the divisor is zero: callers decide whether a missing denominator means "no figure".
     */
    public static function div(string $dividend, string $divisor): string
    {
        return bccomp(self::of($divisor), '0', self::Scale) === 0 ? self::of('0') : bcdiv(self::of($dividend), self::of($divisor), self::Scale);
    }

    public static function compare(string $left, string $right): int
    {
        return bccomp(self::of($left), self::of($right), self::Scale);
    }

    public static function isPositive(string $value): bool
    {
        return self::compare($value, '0') > 0;
    }

    public static function round(string $value, int $scale): string
    {
        return bcadd(bcround(self::of($value), $scale, RoundingMode::HalfAwayFromZero), '0', $scale);
    }
}
