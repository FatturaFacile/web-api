<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Support;

use InvalidArgumentException;

/**
 * Exact decimal arithmetic for manufacturing quantities and costs, built on bcmath. Intermediate results keep 10
 * decimals; stored amounts are rounded half up to 2 decimals, quantities to the 4 decimals of the stock ledger. Floats
 * are accepted only at the boundary (the inventory module's float API) and converted once.
 */
final class Money
{
    private const int Scale = 10;

    /**
     * Normalizes a numeric input to a decimal string with the internal scale.
     */
    public static function of(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_float($value)) {
            $value = sprintf('%.'.self::Scale.'F', $value);
        }

        $value = trim((string) $value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Invalid decimal amount [{$value}].");
        }

        return bcadd($value, '0', self::Scale);
    }

    /**
     * Sum of two amounts.
     */
    public static function add(string|int|float|null $left, string|int|float|null $right): string
    {
        return bcadd(self::of($left), self::of($right), self::Scale);
    }

    /**
     * Difference of two amounts.
     */
    public static function sub(string|int|float|null $left, string|int|float|null $right): string
    {
        return bcsub(self::of($left), self::of($right), self::Scale);
    }

    /**
     * Product of two amounts.
     */
    public static function mul(string|int|float|null $left, string|int|float|null $right): string
    {
        return bcmul(self::of($left), self::of($right), self::Scale);
    }

    /**
     * Quotient of two amounts; a zero divisor gives zero.
     */
    public static function div(string|int|float|null $left, string|int|float|null $right): string
    {
        $divisor = self::of($right);

        return self::isZero($divisor) ? '0' : bcdiv(self::of($left), $divisor, self::Scale);
    }

    /**
     * Compares two amounts: -1, 0 or 1.
     */
    public static function compare(string|int|float|null $left, string|int|float|null $right): int
    {
        return bccomp(self::of($left), self::of($right), self::Scale);
    }

    /**
     * Whether the amount is greater than zero.
     */
    public static function isPositive(string|int|float|null $value): bool
    {
        return self::compare($value, '0') > 0;
    }

    /**
     * Whether the amount is less than zero.
     */
    public static function isNegative(string|int|float|null $value): bool
    {
        return self::compare($value, '0') < 0;
    }

    /**
     * Whether the amount is zero.
     */
    public static function isZero(string|int|float|null $value): bool
    {
        return self::compare($value, '0') === 0;
    }

    /**
     * The smaller of two amounts.
     */
    public static function min(string|int|float|null $left, string|int|float|null $right): string
    {
        return self::compare($left, $right) <= 0 ? self::of($left) : self::of($right);
    }

    /**
     * The larger of two amounts.
     */
    public static function max(string|int|float|null $left, string|int|float|null $right): string
    {
        return self::compare($left, $right) >= 0 ? self::of($left) : self::of($right);
    }

    /**
     * Absolute value.
     */
    public static function abs(string|int|float|null $value): string
    {
        $normalized = self::of($value);

        return self::isNegative($normalized) ? bcmul($normalized, '-1', self::Scale) : $normalized;
    }

    /**
     * Rounds half up (away from zero) to the given decimals and returns the stored representation.
     */
    public static function round(string|int|float|null $value, int $decimals = 2): string
    {
        $normalized = self::of($value);
        $offset = '0.'.str_repeat('0', $decimals).'5';
        $rounded = self::isNegative($normalized)
            ? bcsub($normalized, $offset, $decimals)
            : bcadd($normalized, $offset, $decimals);

        return self::isZero($rounded) ? bcadd('0', '0', $decimals) : $rounded;
    }

    /**
     * A quantity rounded half up to the 4 decimals of the stock ledger.
     */
    public static function qty(string|int|float|null $value): string
    {
        return self::round($value, 4);
    }

    /**
     * Rounds up to the next multiple (lot size multiple); a zero multiple leaves the value unchanged.
     */
    public static function ceilToMultiple(string|int|float|null $value, string|int|float|null $multiple): string
    {
        if (! self::isPositive($multiple)) {
            return self::of($value);
        }

        $units = bcdiv(self::of($value), self::of($multiple), 0);
        if (self::compare(bcmul($units, self::of($multiple), self::Scale), $value) < 0) {
            $units = bcadd($units, '1', 0);
        }

        return bcmul($units, self::of($multiple), self::Scale);
    }

    /**
     * Float view of a quantity for the inventory module, whose public services take floats; never used for arithmetic.
     */
    public static function qtyToFloat(string|int|float|null $value): float
    {
        return (float) self::qty($value);
    }

    /**
     * Float view of an amount for event payloads; never used for arithmetic.
     */
    public static function toFloat(string|int|float|null $value): float
    {
        return (float) self::round($value);
    }
}
