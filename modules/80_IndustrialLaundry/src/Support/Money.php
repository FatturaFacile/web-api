<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Support;

use Modules\GeneralLedger\Services\Tax\Decimal;
use RoundingMode;

/**
 * Exact decimal arithmetic of the module on top of the ledger's Decimal: amounts are strings with four decimals, weights with
 * three. Floats only ever enter through from() (what a database driver may return) and never leave.
 */
final class Money
{
    public const int Scale = 4;

    public const int WeightScale = 3;

    public static function round(string|int|float|null $value, int $scale = self::Scale): string
    {
        $rounded = bcround(Decimal::normalize(self::text($value)), $scale, RoundingMode::HalfAwayFromZero);

        return bcadd($rounded, '0', $scale);
    }

    public static function weight(string|int|float|null $value): string
    {
        return self::round($value, self::WeightScale);
    }

    public static function from(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::round('0');
        }

        return self::round(is_float($value) ? sprintf('%.8F', $value) : (string) $value);
    }

    public static function add(string $left, string $right): string
    {
        return self::round(Decimal::add($left, $right));
    }

    public static function sub(string $left, string $right): string
    {
        return self::round(Decimal::sub($left, $right));
    }

    public static function mul(string $left, string $right): string
    {
        return self::round(Decimal::mul($left, $right));
    }

    public static function percentOf(string $base, string $percent): string
    {
        return self::round(Decimal::div(Decimal::mul($base, $percent), '100'));
    }

    public static function compare(string $left, string $right): int
    {
        return Decimal::compare($left, $right);
    }

    public static function isZero(string $value): bool
    {
        return Decimal::isZero(self::round($value));
    }

    public static function isPositive(string $value): bool
    {
        return Decimal::isPositive(self::round($value));
    }

    /**
     * @param  iterable<mixed>  $values
     */
    public static function sum(iterable $values): string
    {
        $total = '0';

        foreach ($values as $value) {
            $total = Decimal::add($total, self::from($value));
        }

        return self::round($total);
    }

    private static function text(string|int|float|null $value): string
    {
        return match (true) {
            $value === null => '0',
            is_float($value) => sprintf('%.8F', $value),
            default => (string) $value,
        };
    }
}
