<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Support;

use Modules\GeneralLedger\Services\Tax\Decimal;
use RoundingMode;

/**
 * Exact decimal arithmetic of the module on top of the ledger's Decimal: amounts and rates are strings with four decimals,
 * floats only ever enter through from() (what a database driver may return) and never leave.
 */
final class Money
{
    public const int Scale = 4;

    /**
     * Rounds half away from zero to the given scale.
     */
    public static function round(string|int|float|null $value, int $scale = self::Scale): string
    {
        $rounded = bcround(Decimal::normalize(self::text($value)), $scale, RoundingMode::HalfAwayFromZero);

        return bcadd($rounded, '0', $scale);
    }

    /**
     * A database value (string, int or float) as a four-decimal string.
     */
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

    public static function div(string $left, string $right): string
    {
        return self::round(Decimal::div($left, $right));
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

    public static function isNegative(string $value): bool
    {
        return Decimal::isNegative(self::round($value));
    }

    public static function abs(string $value): string
    {
        return self::round(Decimal::abs($value));
    }

    public static function max(string $left, string $right): string
    {
        return self::compare($left, $right) >= 0 ? self::round($left) : self::round($right);
    }

    public static function min(string $left, string $right): string
    {
        return self::compare($left, $right) <= 0 ? self::round($left) : self::round($right);
    }

    /**
     * The sum of a list of amounts.
     *
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

    /**
     * The combined discount of a cascade (1st, 2nd, 3rd discount, Ce.Di.): 100 - 100 x prod(1 - d/100), in percent.
     *
     * @param  list<string|int|float|null>  $percents
     */
    public static function cascadeDiscount(array $percents): string
    {
        $factor = '1';

        foreach ($percents as $percent) {
            $factor = Decimal::mul($factor, Decimal::sub('1', Decimal::div(self::from($percent), '100')));
        }

        return self::round(Decimal::mul('100', Decimal::sub('1', $factor)));
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
