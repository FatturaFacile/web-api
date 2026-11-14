<?php

declare(strict_types=1);

namespace Modules\Facility\Support;

/**
 * Decimal arithmetic on numeric strings (bcmath) for meter values, consumptions and costs. Rounding is half away from
 * zero; floats are never used for amounts.
 */
final class Decimal
{
    public static function of(string|int|float|null $value, int $scale = 3): string
    {
        if ($value === null || $value === '') {
            return self::round('0', $scale);
        }

        if (is_float($value)) {
            $value = number_format($value, 8, '.', '');
        }

        return self::round((string) $value, $scale);
    }

    public static function add(string $left, string $right, int $scale = 3): string
    {
        return bcadd($left, $right, $scale);
    }

    public static function sub(string $left, string $right, int $scale = 3): string
    {
        return bcsub($left, $right, $scale);
    }

    public static function mul(string $left, string $right, int $scale = 2): string
    {
        return self::round(bcmul($left, $right, $scale + 8), $scale);
    }

    public static function compare(string $left, string $right, int $scale = 6): int
    {
        return bccomp($left, $right, $scale);
    }

    public static function round(string $value, int $scale = 2): string
    {
        $offset = '0.'.str_repeat('0', $scale).'5';

        return bccomp($value, '0', $scale + 6) >= 0
            ? bcadd($value, $offset, $scale)
            : bcsub($value, $offset, $scale);
    }
}
