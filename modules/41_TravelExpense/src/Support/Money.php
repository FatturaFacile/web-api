<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Support;

/**
 * Amounts as decimal strings, calculated with bcmath at two decimals (rates at four, percentages at four).
 */
final class Money
{
    private const int Scale = 2;

    public static function normalize(string|int|float|null $amount): string
    {
        $value = is_float($amount) ? number_format($amount, 6, '.', '') : trim((string) ($amount ?? '0'));

        return bcadd(preg_match('/^-?\d+(\.\d+)?$/', $value) === 1 ? $value : '0', '0', self::Scale);
    }

    public static function add(string $left, string $right): string
    {
        return bcadd($left, $right, self::Scale);
    }

    public static function sub(string $left, string $right): string
    {
        return bcsub($left, $right, self::Scale);
    }

    public static function compare(string $left, string $right): int
    {
        return bccomp($left, $right, self::Scale);
    }

    public static function min(string $left, string $right): string
    {
        return self::compare($left, $right) <= 0 ? self::normalize($left) : self::normalize($right);
    }

    public static function abs(string $amount): string
    {
        return ltrim($amount, '-') === '' ? '0.00' : self::normalize(ltrim($amount, '-'));
    }

    /**
     * An amount multiplied by a factor (a day fraction, a number of kilometres), rounded half up to the cent.
     */
    public static function multiply(string $amount, string $factor): string
    {
        return self::round(bcmul($amount, $factor, 8));
    }

    /**
     * The percentage of an amount, rounded half up to the cent.
     */
    public static function percentage(string $amount, string $percentage): string
    {
        return self::round(bcdiv(bcmul($amount, $percentage, 8), '100', 8));
    }

    private static function round(string $value): string
    {
        return bcadd($value, str_starts_with($value, '-') ? '-0.005' : '0.005', self::Scale);
    }
}
