<?php

declare(strict_types=1);

namespace Modules\HumanResources\Support;

use RoundingMode;

/**
 * Exact decimal arithmetic on numeric strings for hours and amounts of Human Resources (bcmath, scale 10 while
 * computing, results rounded half away from zero). Floats received at the edges of the legacy service contracts are
 * converted once with of().
 */
final class Decimal
{
    private const int Scale = 10;

    /**
     * The decimal string of a value; a float is formatted with 6 decimals first, so binary noise never enters.
     */
    public static function of(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $text = is_float($value) ? number_format($value, 6, '.', '') : trim((string) $value);

        return bcadd(is_numeric($text) ? $text : '0', '0', self::Scale);
    }

    public static function add(string $left, string $right): string
    {
        return bcadd($left, $right, self::Scale);
    }

    public static function sub(string $left, string $right): string
    {
        return bcsub($left, $right, self::Scale);
    }

    public static function mul(string $left, string $right): string
    {
        return bcmul($left, $right, self::Scale);
    }

    public static function div(string $dividend, string $divisor): string
    {
        return bccomp($divisor, '0', self::Scale) === 0 ? '0' : bcdiv($dividend, $divisor, self::Scale);
    }

    public static function cmp(string $left, string $right): int
    {
        return bccomp($left, $right, self::Scale);
    }

    public static function max(string $left, string $right): string
    {
        return self::cmp($left, $right) >= 0 ? $left : $right;
    }

    public static function min(string $left, string $right): string
    {
        return self::cmp($left, $right) <= 0 ? $left : $right;
    }

    /**
     * Rounded half away from zero to the given places, as a plain string ("12.50").
     */
    public static function round(string $value, int $places = 2): string
    {
        return bcround($value, $places, RoundingMode::HalfAwayFromZero);
    }
}
