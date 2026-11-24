<?php

declare(strict_types=1);

namespace Modules\Projects\Support;

use InvalidArgumentException;

/**
 * Exact arithmetic on decimal strings for the amounts of a commessa (costs, rates, budgets, revenue), built on bcmath.
 *
 * Floats never enter a calculation: database decimals arrive as strings and request numbers are normalised first.
 * Intermediate results keep ten decimals; amounts are rounded to two decimals half away from zero only when stored.
 */
final class Money
{
    private const int SCALE = 10;

    /**
     * Normalises an amount coming from the database, a request or a DTO. Null and empty strings count as zero.
     */
    public static function of(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return bcadd('0', '0', self::SCALE);
        }

        $text = is_float($value) ? self::fromFloat($value) : trim((string) $value);

        if (preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $text) !== 1) {
            throw new InvalidArgumentException("Invalid decimal value [{$text}].");
        }

        return bcadd($text, '0', self::SCALE);
    }

    public static function add(string|int|float|null ...$values): string
    {
        $total = self::of(0);

        foreach ($values as $value) {
            $total = bcadd($total, self::of($value), self::SCALE);
        }

        return $total;
    }

    public static function sub(string|int|float|null $left, string|int|float|null $right): string
    {
        return bcsub(self::of($left), self::of($right), self::SCALE);
    }

    public static function mul(string|int|float|null $left, string|int|float|null $right): string
    {
        return bcmul(self::of($left), self::of($right), self::SCALE);
    }

    /**
     * Division that answers null when the divisor is zero, so ratios such as CPI stay undefined instead of failing.
     */
    public static function div(string|int|float|null $left, string|int|float|null $right): ?string
    {
        if (self::isZero($right)) {
            return null;
        }

        return bcdiv(self::of($left), self::of($right), self::SCALE);
    }

    public static function compare(string|int|float|null $left, string|int|float|null $right): int
    {
        return bccomp(self::of($left), self::of($right), self::SCALE);
    }

    public static function isZero(string|int|float|null $value): bool
    {
        return self::compare($value, 0) === 0;
    }

    public static function isPositive(string|int|float|null $value): bool
    {
        return self::compare($value, 0) > 0;
    }

    public static function isNegative(string|int|float|null $value): bool
    {
        return self::compare($value, 0) < 0;
    }

    public static function max(string|int|float|null $left, string|int|float|null $right): string
    {
        return self::compare($left, $right) >= 0 ? self::of($left) : self::of($right);
    }

    public static function min(string|int|float|null $left, string|int|float|null $right): string
    {
        return self::compare($left, $right) <= 0 ? self::of($left) : self::of($right);
    }

    public static function neg(string|int|float|null $value): string
    {
        return self::sub(0, $value);
    }

    public static function abs(string|int|float|null $value): string
    {
        return self::isNegative($value) ? self::neg($value) : self::of($value);
    }

    /**
     * Rounds half away from zero to the given number of decimals and returns the plain decimal string.
     */
    public static function round(string|int|float|null $value, int $decimals = 2): string
    {
        $normalized = self::of($value);
        $half = '0.'.str_repeat('0', $decimals).'5';
        $adjusted = self::isNegative($normalized)
            ? bcsub($normalized, $half, self::SCALE)
            : bcadd($normalized, $half, self::SCALE);

        $rounded = bcadd($adjusted, '0', $decimals);

        return self::isZero($rounded) ? bcadd('0', '0', $decimals) : $rounded;
    }

    /**
     * Amount for storage and for API output, with two decimals.
     */
    public static function amount(string|int|float|null $value): string
    {
        return self::round($value, 2);
    }

    /**
     * Percentage with two decimals, from a ratio (0.25 gives 25.00). A null ratio stays null.
     */
    public static function percent(?string $ratio): ?string
    {
        return $ratio === null ? null : self::round(self::mul($ratio, 100), 2);
    }

    private static function fromFloat(float $value): string
    {
        $text = number_format($value, 10, '.', '');

        return $text === '-0.0000000000' ? '0' : $text;
    }
}
