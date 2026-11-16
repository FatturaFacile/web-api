<?php

declare(strict_types=1);

namespace Modules\Configurator\Services;

use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Decimal helpers of the configurator on top of the fiscal Decimal class: rounding to a given scale (half away from
 * zero, like the document totals) and percentages. Amounts are always decimal strings, never floats.
 */
final class ConfiguratorMath
{
    /**
     * Round half away from zero to the scale given (0 to 8 decimals).
     */
    public static function round(string|int $value, int $scale): string
    {
        $normalized = Decimal::normalize($value);
        $half = $scale === 0 ? '0.5' : '0.'.str_repeat('0', $scale).'5';
        $shifted = Decimal::isNegative($normalized) ? Decimal::sub($normalized, $half) : Decimal::add($normalized, $half);

        return bcadd($shifted, '0', $scale);
    }

    public static function round4(string|int $value): string
    {
        return self::round($value, 4);
    }

    /**
     * The percentage of an amount, not rounded.
     */
    public static function percentOf(string|int $amount, string|int $percent): string
    {
        return Decimal::mul($amount, Decimal::div($percent, '100'));
    }

    /**
     * Margin of a revenue over a cost in percent of the revenue, rounded to 2 decimals; null when there is no revenue.
     */
    public static function marginPercent(string $revenue, string $cost): ?string
    {
        if (! Decimal::isPositive($revenue)) {
            return null;
        }

        return Decimal::round2(Decimal::mul(Decimal::div(Decimal::sub($revenue, $cost), $revenue), '100'));
    }

    /**
     * Whether a decimal string is a multiple of the step (both with at most 4 decimals).
     */
    public static function isMultipleOf(string $value, string $step): bool
    {
        if (! Decimal::isPositive($step)) {
            return true;
        }

        return Decimal::isZero(bcmod(self::round($value, 4), self::round($step, 4), 4));
    }

    /**
     * A plain decimal string without trailing zeros, for descriptions and payloads read by people.
     */
    public static function trim(string|int $value): string
    {
        $text = Decimal::normalize($value);

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
