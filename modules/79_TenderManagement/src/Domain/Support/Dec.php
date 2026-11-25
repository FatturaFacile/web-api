<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Domain\Support;

/**
 * Exact decimal arithmetic on strings (bcmath). Floats never enter a tender calculation: database decimals arrive as strings,
 * request numbers are normalised first, intermediate results keep ten decimals and only the stored figure is rounded
 * (half away from zero).
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

    /**
     * Division; a zero divisor yields zero instead of an error.
     */
    public static function div(string|int|float|null $a, string|int|float|null $b): string
    {
        $divisor = self::of($b);

        return bccomp($divisor, '0', self::SCALE) === 0 ? self::of(0) : bcdiv(self::of($a), $divisor, self::SCALE);
    }

    public static function cmp(string|int|float|null $a, string|int|float|null $b): int
    {
        return bccomp(self::of($a), self::of($b), self::SCALE);
    }

    public static function isPositive(string|int|float|null $value): bool
    {
        return self::cmp($value, 0) > 0;
    }

    public static function min(string|int|float|null $a, string|int|float|null $b): string
    {
        return self::cmp($a, $b) <= 0 ? self::of($a) : self::of($b);
    }

    public static function max(string|int|float|null $a, string|int|float|null $b): string
    {
        return self::cmp($a, $b) >= 0 ? self::of($a) : self::of($b);
    }

    public static function round(string|int|float|null $value, int $scale = 2): string
    {
        $number = self::of($value);
        $half = '0.'.str_repeat('0', $scale).'5';

        return bccomp($number, '0', self::SCALE) < 0
            ? bcsub($number, $half, $scale)
            : bcadd($number, $half, $scale);
    }

    public static function money(string|int|float|null $value): string
    {
        return self::round($value, 2);
    }

    public static function percent(string|int|float|null $value): string
    {
        return self::round($value, 4);
    }

    /**
     * Average of the values; null when there are none.
     *
     * @param  list<string|int|float|null>  $values
     */
    public static function mean(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        $sum = self::of(0);
        foreach ($values as $value) {
            $sum = self::add($sum, $value);
        }

        return self::div($sum, count($values));
    }

    /**
     * Median of the values; null when there are none.
     *
     * @param  list<string|int|float|null>  $values
     */
    public static function median(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        $sorted = array_map(self::of(...), $values);
        usort($sorted, static fn (string $a, string $b): int => bccomp($a, $b, self::SCALE));
        $count = count($sorted);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $sorted[$middle] : self::div(self::add($sorted[$middle - 1], $sorted[$middle]), 2);
    }
}
