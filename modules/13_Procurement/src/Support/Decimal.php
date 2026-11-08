<?php

declare(strict_types=1);

namespace Modules\Procurement\Support;

/**
 * Decimal arithmetic on numeric strings (bcmath) for quantities, prices and amounts of procurement documents.
 * Rounding is half away from zero.
 */
final class Decimal
{
    /**
     * Normalizes a number to a decimal string rounded to the given scale.
     */
    public static function of(string|int|float|null $value, int $scale = 4): string
    {
        if ($value === null || $value === '') {
            return self::round('0', $scale);
        }

        if (is_float($value)) {
            $value = number_format($value, 8, '.', '');
        }

        return self::round((string) $value, $scale);
    }

    public static function add(string $left, string $right, int $scale = 4): string
    {
        return bcadd($left, $right, $scale);
    }

    public static function sub(string $left, string $right, int $scale = 4): string
    {
        return bcsub($left, $right, $scale);
    }

    public static function mul(string $left, string $right, int $scale = 4): string
    {
        return self::round(bcmul($left, $right, $scale + 4), $scale);
    }

    /**
     * Division; a zero divisor gives zero.
     */
    public static function div(string $left, string $right, int $scale = 4): string
    {
        if (bccomp($right, '0', 8) === 0) {
            return self::round('0', $scale);
        }

        return self::round(bcdiv($left, $right, $scale + 4), $scale);
    }

    public static function round(string $value, int $scale = 2): string
    {
        $offset = '0.'.str_repeat('0', $scale).'5';

        return bccomp($value, '0', $scale + 6) >= 0
            ? bcadd($value, $offset, $scale)
            : bcsub($value, $offset, $scale);
    }

    public static function cmp(string $left, string $right, int $scale = 4): int
    {
        return bccomp($left, $right, $scale);
    }

    public static function isPositive(string $value): bool
    {
        return bccomp($value, '0', 6) > 0;
    }

    public static function abs(string $value, int $scale = 4): string
    {
        return bccomp($value, '0', $scale) < 0 ? bcmul($value, '-1', $scale) : bcadd($value, '0', $scale);
    }

    public static function max(string $left, string $right, int $scale = 4): string
    {
        return bccomp($left, $right, $scale) >= 0 ? bcadd($left, '0', $scale) : bcadd($right, '0', $scale);
    }

    public static function min(string $left, string $right, int $scale = 4): string
    {
        return bccomp($left, $right, $scale) <= 0 ? bcadd($left, '0', $scale) : bcadd($right, '0', $scale);
    }

    /**
     * The percentage of `$part` over `$whole`; zero when the whole is zero.
     */
    public static function percentOf(string $part, string $whole, int $scale = 2): string
    {
        return self::div(bcmul($part, '100', 8), $whole, $scale);
    }

    /**
     * `$value` increased by `$percent` percent.
     */
    public static function plusPercent(string $value, string $percent, int $scale = 4): string
    {
        return self::mul($value, bcadd('1', bcdiv($percent, '100', 8), 8), $scale);
    }
}
