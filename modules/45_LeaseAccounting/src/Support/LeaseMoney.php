<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Support;

use InvalidArgumentException;
use RoundingMode;

/**
 * Exact arithmetic on decimal strings for lease amounts, built on bcmath. Floats are never accepted: an amount is a
 * string with two decimals, a rate or a discount factor a string with sixteen.
 */
final class LeaseMoney
{
    /** Scale of discount factors and rates. */
    public const int RateScale = 16;

    /**
     * @throws InvalidArgumentException when the value is not a decimal number
     */
    public static function normalize(string|int $value, int $scale = 2): string
    {
        $text = trim((string) $value);

        if (preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $text) !== 1) {
            throw new InvalidArgumentException("Invalid decimal value [{$text}].");
        }

        return bcadd($text, '0', $scale);
    }

    /**
     * Rounds to two decimals, half away from zero.
     */
    public static function round(string $value): string
    {
        return bcadd(bcround(bcadd($value, '0', self::RateScale), 2, RoundingMode::HalfAwayFromZero), '0', 2);
    }

    public static function add(string $left, string $right): string
    {
        return bcadd($left, $right, self::RateScale);
    }

    public static function sub(string $left, string $right): string
    {
        return bcsub($left, $right, self::RateScale);
    }

    public static function mul(string $left, string $right): string
    {
        return bcmul($left, $right, self::RateScale);
    }

    public static function compare(string $left, string $right): int
    {
        return bccomp($left, $right, self::RateScale);
    }

    public static function isPositive(string $value): bool
    {
        return self::compare($value, '0') > 0;
    }

    public static function isZero(string $value): bool
    {
        return self::compare($value, '0') === 0;
    }

    public static function abs(string $value): string
    {
        return self::compare($value, '0') < 0 ? self::sub('0', $value) : $value;
    }
}
