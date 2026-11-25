<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Support;

/**
 * Amounts as decimal strings calculated with bcmath: two decimals for money, four for rates and percentages, rounded half up.
 */
final class Decimal
{
    public static function normalize(string|int|float|null $value, int $scale = 2): string
    {
        $text = is_float($value) ? number_format($value, 8, '.', '') : trim((string) ($value ?? '0'));
        $text = preg_match('/^-?\d+(\.\d+)?$/', $text) === 1 ? $text : '0';

        return self::round($text, $scale);
    }

    public static function round(string $value, int $scale = 2): string
    {
        $negative = str_starts_with($value, '-');
        $absolute = ltrim($value, '-');
        $rounded = self::truncate(bcadd($absolute, '0.'.str_repeat('0', $scale).'5', $scale + 1), $scale);

        return $negative && bccomp($rounded, '0', $scale) !== 0 ? '-'.$rounded : $rounded;
    }

    public static function add(string $left, string $right, int $scale = 2): string
    {
        return bcadd($left, $right, $scale);
    }

    public static function sub(string $left, string $right, int $scale = 2): string
    {
        return bcsub($left, $right, $scale);
    }

    public static function mul(string $left, string $right, int $scale = 2): string
    {
        return self::round(bcmul($left, $right, $scale + 6), $scale);
    }

    public static function div(string $left, string $right, int $scale = 2): string
    {
        return bccomp($right, '0', 8) === 0 ? self::round('0', $scale) : self::round(bcdiv($left, $right, $scale + 6), $scale);
    }

    public static function cmp(string $left, string $right, int $scale = 2): int
    {
        return bccomp($left, $right, $scale);
    }

    public static function min(string $left, string $right): string
    {
        return self::cmp($left, $right) <= 0 ? $left : $right;
    }

    public static function isPositive(string $value): bool
    {
        return bccomp($value, '0', 2) > 0;
    }

    private static function truncate(string $value, int $scale): string
    {
        $parts = explode('.', $value);

        return $scale === 0 ? $parts[0] : $parts[0].'.'.substr(str_pad($parts[1] ?? '', $scale, '0'), 0, $scale);
    }
}
