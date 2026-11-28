<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Support;

/**
 * Exact decimal arithmetic of the projections (bcmath): amounts are strings, never floats.
 */
final class Amount
{
    private const int SCALE = 6;

    public static function of(mixed $value): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return '0.000000';
        }

        $text = is_float($value) ? sprintf('%.'.self::SCALE.'F', $value) : (string) $value;

        // Scientific notation (1.0E-5) is not accepted by bcmath.
        if (stripos($text, 'e') !== false) {
            $text = sprintf('%.'.self::SCALE.'F', (float) $text);
        }

        return bcadd($text, '0', self::SCALE);
    }

    public static function add(mixed $a, mixed $b): string
    {
        return bcadd(self::of($a), self::of($b), self::SCALE);
    }

    public static function sub(mixed $a, mixed $b): string
    {
        return bcsub(self::of($a), self::of($b), self::SCALE);
    }

    public static function mul(mixed $a, mixed $b): string
    {
        return bcmul(self::of($a), self::of($b), self::SCALE);
    }

    public static function div(mixed $a, mixed $b): string
    {
        return self::isZero($b) ? self::of(0) : bcdiv(self::of($a), self::of($b), self::SCALE);
    }

    public static function neg(mixed $a): string
    {
        return bcmul(self::of($a), '-1', self::SCALE);
    }

    public static function cmp(mixed $a, mixed $b): int
    {
        return bccomp(self::of($a), self::of($b), self::SCALE);
    }

    public static function isZero(mixed $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    public static function min(mixed $a, mixed $b): string
    {
        return self::cmp($a, $b) <= 0 ? self::of($a) : self::of($b);
    }

    /**
     * Money, two decimals (half away from zero).
     */
    public static function money(mixed $a): string
    {
        return self::round($a, 2);
    }

    public static function quantity(mixed $a): string
    {
        return self::round($a, 4);
    }

    public static function round(mixed $a, int $scale): string
    {
        $value = self::of($a);
        $half = '0.'.str_repeat('0', $scale).'5';
        $adjusted = bccomp($value, '0', self::SCALE) >= 0 ? bcadd($value, $half, self::SCALE) : bcsub($value, $half, self::SCALE);
        $result = bcadd($adjusted, '0', $scale);

        // bcadd truncates: a negative zero never appears.
        return bccomp($result, '0', $scale) === 0 ? bcadd('0', '0', $scale) : $result;
    }
}
