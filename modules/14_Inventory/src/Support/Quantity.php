<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use RoundingMode;

/**
 * Exact decimal arithmetic (bcmath) for stock quantities (4 decimals, the scale of every quantity column) and stock
 * values (2 decimals). Floats coming from older call sites are converted once, at the boundary, and never computed on.
 * Rounding is half away from zero.
 */
final class Quantity
{
    public const int SCALE = 4;

    /** Scale of intermediate products (quantity x unit cost with 4 decimals each). */
    private const int WORK_SCALE = 10;

    /**
     * A quantity as a decimal string with 4 decimals; null and empty mean zero.
     */
    public static function of(string|int|float|null $value, int $scale = self::SCALE): string
    {
        if ($value === null || $value === '') {
            return bcadd('0', '0', $scale);
        }

        if (is_float($value)) {
            $value = number_format($value, 8, '.', '');
        }

        return bcadd(bcround(trim((string) $value), $scale, RoundingMode::HalfAwayFromZero), '0', $scale);
    }

    public static function add(string|int|float|null $left, string|int|float|null $right, int $scale = self::SCALE): string
    {
        return self::of(bcadd(self::of($left, self::WORK_SCALE), self::of($right, self::WORK_SCALE), self::WORK_SCALE), $scale);
    }

    public static function sub(string|int|float|null $left, string|int|float|null $right, int $scale = self::SCALE): string
    {
        return self::of(bcsub(self::of($left, self::WORK_SCALE), self::of($right, self::WORK_SCALE), self::WORK_SCALE), $scale);
    }

    public static function mul(string|int|float|null $left, string|int|float|null $right, int $scale = self::SCALE): string
    {
        return self::of(bcmul(self::of($left, self::WORK_SCALE), self::of($right, self::WORK_SCALE), self::WORK_SCALE), $scale);
    }

    /**
     * Division; null when the divisor is zero, so a caller never shows a made-up ratio.
     */
    public static function div(string|int|float|null $left, string|int|float|null $right, int $scale = self::SCALE): ?string
    {
        if (self::isZero($right)) {
            return null;
        }

        return self::of(bcdiv(self::of($left, self::WORK_SCALE), self::of($right, self::WORK_SCALE), self::WORK_SCALE), $scale);
    }

    /**
     * A stock value: quantity times unit cost, rounded to cents.
     */
    public static function value(string|int|float|null $quantity, string|int|float|null $unitCost): string
    {
        return self::mul($quantity, $unitCost, 2);
    }

    public static function compare(string|int|float|null $left, string|int|float|null $right): int
    {
        return bccomp(self::of($left, self::WORK_SCALE), self::of($right, self::WORK_SCALE), self::WORK_SCALE);
    }

    public static function isPositive(string|int|float|null $value): bool
    {
        return self::compare($value, '0') > 0;
    }

    public static function isNegative(string|int|float|null $value): bool
    {
        return self::compare($value, '0') < 0;
    }

    public static function isZero(string|int|float|null $value): bool
    {
        return self::compare($value, '0') === 0;
    }

    public static function abs(string|int|float|null $value, int $scale = self::SCALE): string
    {
        $normalized = self::of($value, $scale);

        return str_starts_with($normalized, '-') ? substr($normalized, 1) : $normalized;
    }

    public static function min(string|int|float|null $left, string|int|float|null $right, int $scale = self::SCALE): string
    {
        return self::compare($left, $right) <= 0 ? self::of($left, $scale) : self::of($right, $scale);
    }

    public static function max(string|int|float|null $left, string|int|float|null $right, int $scale = self::SCALE): string
    {
        return self::compare($left, $right) >= 0 ? self::of($left, $scale) : self::of($right, $scale);
    }
}
