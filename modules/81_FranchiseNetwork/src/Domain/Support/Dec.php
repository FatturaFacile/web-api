<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Support;

/**
 * Exact decimal arithmetic on strings (bcmath) for royalties, fees and settlements. Floats never enter a money calculation:
 * intermediate results keep ten decimals and only the stored figure is rounded, half away from zero.
 */
final class Dec
{
    private const int SCALE = 10;

    public static function of(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0.0000000000';
        }

        $text = is_float($value) ? rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') : trim((string) $value);

        return bcadd($text === '' ? '0' : $text, '0', self::SCALE);
    }

    public static function add(string|int|float|null $a, string|int|float|null $b): string
    {
        return bcadd(self::of($a), self::of($b), self::SCALE);
    }

    public static function sub(string|int|float|null $a, string|int|float|null $b): string
    {
        return bcsub(self::of($a), self::of($b), self::SCALE);
    }

    public static function mul(string|int|float|null $a, string|int|float|null $b): string
    {
        return bcmul(self::of($a), self::of($b), self::SCALE);
    }

    public static function div(string|int|float|null $a, string|int|float|null $b): string
    {
        return bccomp(self::of($b), '0', self::SCALE) === 0 ? self::of(0) : bcdiv(self::of($a), self::of($b), self::SCALE);
    }

    public static function cmp(string|int|float|null $a, string|int|float|null $b): int
    {
        return bccomp(self::of($a), self::of($b), self::SCALE);
    }

    public static function max(string|int|float|null $a, string|int|float|null $b): string
    {
        return self::cmp($a, $b) >= 0 ? self::of($a) : self::of($b);
    }

    public static function min(string|int|float|null $a, string|int|float|null $b): string
    {
        return self::cmp($a, $b) <= 0 ? self::of($a) : self::of($b);
    }

    /**
     * Rounds half away from zero to the given number of decimals.
     */
    public static function round(string|int|float|null $value, int $decimals = 2): string
    {
        $text = self::of($value);
        $half = '0.'.str_repeat('0', $decimals).'5';

        return bccomp($text, '0', self::SCALE) >= 0 ? bcadd($text, $half, $decimals) : bcsub($text, $half, $decimals);
    }
}
