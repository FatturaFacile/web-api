<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Support;

use RoundingMode;

/**
 * Exact decimal arithmetic of the module: amounts are strings, never floats. Working precision is four decimals; the
 * statements are rounded to the scale of the rounding mode (two decimals or whole units) with half away from zero.
 */
final class Money
{
    public const int Scale = 4;

    public static function from(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.0000';
        }

        $text = is_float($value) ? sprintf('%.8F', $value) : trim((string) $value);

        if (! is_numeric($text)) {
            return '0.0000';
        }

        return self::round($text, self::Scale);
    }

    public static function round(string $value, int $scale = self::Scale): string
    {
        $rounded = bcround(self::plain($value), $scale, RoundingMode::HalfAwayFromZero);

        // "-0" and "-0.00" become a plain zero.
        return bccomp($rounded, '0', $scale) === 0 ? bcadd('0', '0', $scale) : bcadd($rounded, '0', $scale);
    }

    public static function add(string $left, string $right): string
    {
        return bcadd(self::plain($left), self::plain($right), self::Scale);
    }

    public static function sub(string $left, string $right): string
    {
        return bcsub(self::plain($left), self::plain($right), self::Scale);
    }

    public static function mul(string $left, string $right): string
    {
        return self::round(bcmul(self::plain($left), self::plain($right), 12));
    }

    public static function div(string $left, string $right): ?string
    {
        if (self::isZero($right)) {
            return null;
        }

        return self::round(bcdiv(self::plain($left), self::plain($right), 12));
    }

    public static function neg(string $value): string
    {
        return self::sub('0', $value);
    }

    public static function abs(string $value): string
    {
        return self::isNegative($value) ? self::neg($value) : self::round($value);
    }

    public static function compare(string $left, string $right): int
    {
        return bccomp(self::plain($left), self::plain($right), self::Scale);
    }

    public static function min(string $left, string $right): string
    {
        return self::compare($left, $right) <= 0 ? self::round($left) : self::round($right);
    }

    public static function max(string $left, string $right): string
    {
        return self::compare($left, $right) >= 0 ? self::round($left) : self::round($right);
    }

    public static function isZero(string $value): bool
    {
        return self::compare($value, '0') === 0;
    }

    public static function isNegative(string $value): bool
    {
        return self::compare($value, '0') < 0;
    }

    public static function isPositive(string $value): bool
    {
        return self::compare($value, '0') > 0;
    }

    /**
     * @param  iterable<string>  $values
     */
    public static function sum(iterable $values): string
    {
        $total = '0';

        foreach ($values as $value) {
            $total = self::add($total, $value);
        }

        return self::round($total);
    }

    /**
     * A ratio as a percentage with two decimals, or null when the denominator is zero.
     */
    public static function percent(string $numerator, string $denominator): ?string
    {
        if (self::isZero($denominator)) {
            return null;
        }

        return self::round(bcmul(bcdiv(self::plain($numerator), self::plain($denominator), 12), '100', 12), 2);
    }

    private static function plain(string $value): string
    {
        $value = trim($value);

        if (! is_numeric($value)) {
            return '0';
        }

        // Scientific notation (1.0E+3) is not understood by bcmath.
        if (stripos($value, 'e') !== false) {
            return rtrim(rtrim(sprintf('%.8F', (float) $value), '0'), '.') ?: '0';
        }

        return $value;
    }
}
