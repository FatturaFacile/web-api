<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Services;

use InvalidArgumentException;
use RoundingMode;

/**
 * Exact decimal arithmetic (bcmath) for leave accruals, balances, pro-rata, and Art. 329b CO reductions.
 */
final class LeaveDecimal
{
    private const int SCALE = 8;

    public static function normalize(string|int|float $value): string
    {
        $text = trim((string) $value);

        if (preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $text) !== 1) {
            throw new InvalidArgumentException("Invalid decimal value [{$text}].");
        }

        return bcadd($text, '0', self::SCALE);
    }

    public static function add(string|int|float $left, string|int|float $right): string
    {
        return bcadd(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function sub(string|int|float $left, string|int|float $right): string
    {
        return bcsub(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function mul(string|int|float $left, string|int|float $right): string
    {
        return bcmul(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function div(string|int|float $left, string|int|float $right): string
    {
        $normalizedRight = self::normalize($right);
        if (bccomp($normalizedRight, '0', self::SCALE) === 0) {
            throw new InvalidArgumentException('Division by zero in LeaveDecimal.');
        }

        return bcdiv(self::normalize($left), $normalizedRight, self::SCALE);
    }

    public static function compare(string|int|float $left, string|int|float $right): int
    {
        return bccomp(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function max(string|int|float $left, string|int|float $right): string
    {
        return self::compare($left, $right) >= 0 ? self::normalize($left) : self::normalize($right);
    }

    public static function min(string|int|float $left, string|int|float $right): string
    {
        return self::compare($left, $right) <= 0 ? self::normalize($left) : self::normalize($right);
    }

    public static function round2(string|int|float $value): string
    {
        $rounded = bcround(self::normalize($value), 2, RoundingMode::HalfAwayFromZero);

        return bcadd($rounded, '0', 2);
    }
}
