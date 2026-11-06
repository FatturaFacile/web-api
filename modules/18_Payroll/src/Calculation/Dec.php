<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation;

use InvalidArgumentException;
use OverflowException;
use RoundingMode;

/**
 * Exact decimal arithmetic on strings that behaves like the .NET System.Decimal of the desktop application: results
 * keep at most 28 decimals and a 96-bit mantissa, and what does not fit is rounded half to even. Repeating quotients
 * (an annual salary divided by 13) therefore carry the same last digit as in C#, so that a value on a rounding midpoint
 * falls on the same side. Monetary code never touches floats: every value here is a numeric string.
 */
final class Dec
{
    private const int MaxScale = 28;

    private const int WorkingScale = 60;

    private const string MaxMantissa = '79228162514264337593543950335';

    public static function add(string $left, string $right): string
    {
        return self::fit(bcadd($left, $right, self::MaxScale));
    }

    public static function sub(string $left, string $right): string
    {
        return self::fit(bcsub($left, $right, self::MaxScale));
    }

    public static function mul(string $left, string $right): string
    {
        return self::fit(bcmul($left, $right, self::MaxScale * 2));
    }

    public static function div(string $dividend, string $divisor): string
    {
        return self::fit(bcdiv($dividend, $divisor, self::WorkingScale));
    }

    public static function neg(string $value): string
    {
        return self::fit(bcmul($value, '-1', self::MaxScale));
    }

    public static function cmp(string $left, string $right): int
    {
        return bccomp($left, $right, self::MaxScale);
    }

    public static function isZero(string $value): bool
    {
        return self::cmp($value, '0') === 0;
    }

    public static function isPositive(string $value): bool
    {
        return self::cmp($value, '0') > 0;
    }

    public static function isNegative(string $value): bool
    {
        return self::cmp($value, '0') < 0;
    }

    public static function min(string $left, string $right): string
    {
        return self::cmp($left, $right) <= 0 ? $left : $right;
    }

    public static function max(string $left, string $right): string
    {
        return self::cmp($left, $right) >= 0 ? $left : $right;
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

        return $total;
    }

    /**
     * Math.Round(value, places, MidpointRounding.AwayFromZero).
     */
    public static function round(string $value, int $places = 2): string
    {
        return bcround($value, $places, RoundingMode::HalfAwayFromZero);
    }

    /**
     * Math.Ceiling(value).
     */
    public static function ceil(string $value): string
    {
        return bcceil($value);
    }

    /**
     * ToString("0.##"): rounded away from zero, without trailing zeros.
     */
    public static function trimmed(string $value, int $places = 2): string
    {
        return self::fit(self::round($value, $places));
    }

    /**
     * The canonical form of a numeric string: no trailing zeros, no sign on zero. Rejects anything that is not a
     * plain decimal, such as exponent notation.
     */
    public static function of(string|int $value): string
    {
        $text = trim((string) $value);

        if (preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $text) !== 1) {
            throw new InvalidArgumentException("Invalid decimal value [{$text}].");
        }

        return self::fit(bcadd(ltrim($text, '+'), '0', self::WorkingScale));
    }

    private static function fit(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $absolute = $negative ? substr($value, 1) : $value;
        [$integer, $fraction] = array_pad(explode('.', $absolute, 2), 2, '');
        $integer = ltrim($integer, '0');
        $fraction = rtrim($fraction, '0');

        if (strlen($integer) + strlen($fraction) > 28 || strlen($fraction) > self::MaxScale) {
            [$integer, $fraction] = self::reduce($absolute, strlen($integer));
        }

        if ($integer === '' && $fraction === '') {
            return '0';
        }

        $text = ($integer === '' ? '0' : $integer).($fraction === '' ? '' : '.'.$fraction);

        return $negative ? '-'.$text : $text;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function reduce(string $absolute, int $integerDigits): array
    {
        $scale = min(self::MaxScale, 29 - $integerDigits);

        while ($scale >= 0) {
            $rounded = bcround($absolute, $scale, RoundingMode::HalfEven);

            if (bccomp(ltrim(str_replace('.', '', $rounded), '0') ?: '0', self::MaxMantissa, 0) <= 0) {
                [$integer, $fraction] = array_pad(explode('.', $rounded, 2), 2, '');

                return [ltrim($integer, '0'), rtrim($fraction, '0')];
            }

            $scale--;
        }

        throw new OverflowException('Value was either too large or too small for a decimal.');
    }
}
