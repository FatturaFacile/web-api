<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Support;

use RoundingMode;

/**
 * Decimal arithmetic of the subscription engine on numeric strings (bcmath): amounts are never floats. Intermediate
 * results keep ten decimals; amounts are rounded half away from zero to the cent, unit prices to four decimals.
 */
final class Money
{
    private const int SCALE = 10;

    /**
     * Normalizes a number, a numeric string or null to a bcmath operand.
     */
    public static function of(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_float($value)) {
            $value = number_format($value, self::SCALE, '.', '');
        }

        $text = trim((string) $value);

        return is_numeric($text) ? bcadd($text, '0', self::SCALE) : '0';
    }

    public static function add(string|int|float|null $left, string|int|float|null $right): string
    {
        return bcadd(self::of($left), self::of($right), self::SCALE);
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
     * Division; a zero divisor gives zero instead of an error (an empty period, no customers).
     */
    public static function div(string|int|float|null $left, string|int|float|null $right): string
    {
        $divisor = self::of($right);

        return bccomp($divisor, '0', self::SCALE) === 0 ? '0' : bcdiv(self::of($left), $divisor, self::SCALE);
    }

    public static function neg(string|int|float|null $value): string
    {
        return bcmul(self::of($value), '-1', self::SCALE);
    }

    public static function abs(string|int|float|null $value): string
    {
        return self::isNegative($value) ? self::neg($value) : self::of($value);
    }

    public static function compare(string|int|float|null $left, string|int|float|null $right): int
    {
        return bccomp(self::of($left), self::of($right), self::SCALE);
    }

    public static function isZero(string|int|float|null $value): bool
    {
        return self::compare($value, '0') === 0;
    }

    public static function isPositive(string|int|float|null $value): bool
    {
        return self::compare($value, '0') > 0;
    }

    public static function isNegative(string|int|float|null $value): bool
    {
        return self::compare($value, '0') < 0;
    }

    public static function max(string|int|float|null $left, string|int|float|null $right): string
    {
        return self::compare($left, $right) >= 0 ? self::of($left) : self::of($right);
    }

    public static function min(string|int|float|null $left, string|int|float|null $right): string
    {
        return self::compare($left, $right) <= 0 ? self::of($left) : self::of($right);
    }

    /**
     * @param  iterable<string|int|float|null>  $values
     */
    public static function sum(iterable $values): string
    {
        $total = '0';

        foreach ($values as $value) {
            $total = self::add($total, $value);
        }

        return $total;
    }

    /**
     * Rounded to the cent, as stored in the money columns.
     */
    public static function round2(string|int|float|null $value): string
    {
        return bcround(self::of($value), 2, RoundingMode::HalfAwayFromZero);
    }

    /**
     * Rounded to four decimals, as stored in the unit price and quantity columns.
     */
    public static function round4(string|int|float|null $value): string
    {
        return bcround(self::of($value), 4, RoundingMode::HalfAwayFromZero);
    }

    /**
     * Rounded to a given scale (rates and ratios of the metrics).
     */
    public static function round(string|int|float|null $value, int $scale): string
    {
        return bcround(self::of($value), $scale, RoundingMode::HalfAwayFromZero);
    }
}
