<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Support;

use InvalidArgumentException;
use RoundingMode;

/**
 * Exact arithmetic on decimal strings (bcmath). Amounts and rates are never floats: a float is refused at the boundary.
 */
final class Dec
{
    /** Scale of the intermediate results: wide enough for a large amount times a 6-decimal rate and its inverse. */
    public const int INTERNAL = 18;

    public const int MONEY = 2;

    public const int RATE = 6;

    public static function norm(string $value): string
    {
        $text = trim($value);

        if (preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $text) !== 1) {
            throw new InvalidArgumentException("Invalid decimal value [{$value}].");
        }

        return bcadd($text, '0', self::INTERNAL);
    }

    public static function isDecimal(mixed $value): bool
    {
        return (is_string($value) || is_int($value)) && preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', trim((string) $value)) === 1;
    }

    public static function add(string $left, string $right): string
    {
        return bcadd(self::norm($left), self::norm($right), self::INTERNAL);
    }

    public static function sub(string $left, string $right): string
    {
        return bcsub(self::norm($left), self::norm($right), self::INTERNAL);
    }

    public static function mul(string $left, string $right): string
    {
        return bcmul(self::norm($left), self::norm($right), self::INTERNAL);
    }

    public static function div(string $left, string $right): string
    {
        if (self::isZero($right)) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return bcdiv(self::norm($left), self::norm($right), self::INTERNAL);
    }

    public static function cmp(string $left, string $right): int
    {
        return bccomp(self::norm($left), self::norm($right), self::INTERNAL);
    }

    public static function isZero(string $value): bool
    {
        return self::cmp($value, '0') === 0;
    }

    public static function isPositive(string $value): bool
    {
        return self::cmp($value, '0') === 1;
    }

    public static function isNegative(string $value): bool
    {
        return self::cmp($value, '0') === -1;
    }

    public static function abs(string $value): string
    {
        return self::isNegative($value) ? self::mul($value, '-1') : self::norm($value);
    }

    public static function neg(string $value): string
    {
        return self::mul($value, '-1');
    }

    /**
     * Rounds half away from zero (half-up for positive amounts) to a scale and returns the value with exactly that many
     * decimals.
     */
    public static function round(string $value, int $scale): string
    {
        return bcadd(bcround(self::norm($value), $scale, RoundingMode::HalfAwayFromZero), '0', $scale);
    }

    public static function money(string $value): string
    {
        return self::round($value, self::MONEY);
    }

    public static function rate(string $value): string
    {
        return self::round($value, self::RATE);
    }

    /**
     * Number of decimals of a plain decimal string, trailing zeros ignored.
     */
    public static function decimals(string $value): int
    {
        $text = trim($value);

        if (! str_contains($text, '.')) {
            return 0;
        }

        $text = rtrim($text, '0');
        $dot = strpos($text, '.');

        return $dot === false ? 0 : strlen($text) - $dot - 1;
    }

    /**
     * Swiss cash rounding to 0.05, half up: only for the total of a document issued in CHF, never for accounting
     * counter-values.
     */
    public static function roundToFiveCents(string $value): string
    {
        $twentieths = bcround(bcmul(self::norm($value), '20', self::INTERNAL), 0, RoundingMode::HalfAwayFromZero);

        return bcdiv($twentieths, '20', self::MONEY);
    }
}
