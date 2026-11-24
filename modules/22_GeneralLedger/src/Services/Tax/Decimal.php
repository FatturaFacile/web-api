<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

use InvalidArgumentException;
use RoundingMode;

/**
 * Exact arithmetic on decimal strings for fiscal amounts, built on bcmath. Floats are never accepted.
 */
final class Decimal
{
    /** Scale of the intermediate results: wide enough for a 4-decimal amount times a 6-decimal exchange rate. */
    private const int SCALE = 10;

    public static function normalize(string|int $value): string
    {
        $text = trim((string) $value);

        if (preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $text) !== 1) {
            throw new InvalidArgumentException("Invalid decimal value [{$text}].");
        }

        return bcadd($text, '0', self::SCALE);
    }

    public static function add(string|int $left, string|int $right): string
    {
        return bcadd(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function sub(string|int $left, string|int $right): string
    {
        return bcsub(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function mul(string|int $left, string|int $right): string
    {
        return bcmul(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function div(string|int $left, string|int $right): string
    {
        return bcdiv(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function neg(string|int $value): string
    {
        return self::sub('0', $value);
    }

    public static function abs(string|int $value): string
    {
        return self::isNegative($value) ? self::neg($value) : self::normalize($value);
    }

    public static function compare(string|int $left, string|int $right): int
    {
        return bccomp(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function isPositive(string|int $value): bool
    {
        return self::compare($value, '0') > 0;
    }

    public static function isNegative(string|int $value): bool
    {
        return self::compare($value, '0') < 0;
    }

    public static function isZero(string|int $value): bool
    {
        return self::compare($value, '0') === 0;
    }

    /**
     * Rounds to two decimals, half away from zero (Math.Round with MidpointRounding.AwayFromZero).
     */
    public static function round2(string|int $value): string
    {
        $rounded = bcround(self::normalize($value), 2, RoundingMode::HalfAwayFromZero);

        return bcadd($rounded, '0', 2);
    }

    /**
     * Formats like the .NET "N2" format with the invariant culture: 1,234.50.
     */
    public static function formatGrouped(string|int $value): string
    {
        $rounded = self::round2($value);
        $negative = str_starts_with($rounded, '-');
        [$integer, $fraction] = explode('.', ltrim($rounded, '-'));
        $grouped = strrev(implode(',', str_split(strrev($integer), 3)));

        return ($negative ? '-' : '').$grouped.'.'.$fraction;
    }

    /**
     * Formats a rate like the .NET "0.##" format: 8.1, 22, 2.65.
     */
    public static function formatRate(string|int $value): string
    {
        $trimmed = rtrim(rtrim(self::round2($value), '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
