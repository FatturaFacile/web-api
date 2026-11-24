<?php

declare(strict_types=1);

namespace Modules\SwissVat\Services;

use InvalidArgumentException;
use RoundingMode;

/**
 * Exact decimal arithmetic (bcmath) and Swiss commercial rounding (0.05 CHF / 0.01 CHF) for Swiss VAT.
 */
final class SwissVatDecimal
{
    private const int SCALE = 10;

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
        return bcdiv(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function neg(string|int|float $value): string
    {
        return self::sub('0', $value);
    }

    public static function abs(string|int|float $value): string
    {
        return self::isNegative($value) ? self::neg($value) : self::normalize($value);
    }

    public static function compare(string|int|float $left, string|int|float $right): int
    {
        return bccomp(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function isPositive(string|int|float $value): bool
    {
        return self::compare($value, '0') > 0;
    }

    public static function isNegative(string|int|float $value): bool
    {
        return self::compare($value, '0') < 0;
    }

    public static function isZero(string|int|float $value): bool
    {
        return self::compare($value, '0') === 0;
    }

    public static function round2(string|int|float $value): string
    {
        $rounded = bcround(self::normalize($value), 2, RoundingMode::HalfAwayFromZero);

        return bcadd($rounded, '0', 2);
    }

    public static function round05(string|int|float $value): string
    {
        $twentieths = bcround(self::mul($value, '20'), 0, RoundingMode::HalfAwayFromZero);

        return self::round2(self::div($twentieths, '20'));
    }

    public static function roundByMode(string|int|float $value, string $roundingMode = 'chf_005'): string
    {
        return $roundingMode === 'standard_001' ? self::round2($value) : self::round05($value);
    }
}
