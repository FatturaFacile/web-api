<?php

declare(strict_types=1);

namespace Modules\CRM\Support;

/**
 * Weighted value engine: weighted value = deal value x probability. Probabilities are expressed as a percentage in
 * 0..100 everywhere (database, API and UI); the arithmetic uses bcmath so no float rounding drift reaches the totals.
 */
final class WeightedValue
{
    public static function compute(string|int|float $amount, string|int|float $probabilityPercent): string
    {
        $amount = self::decimal($amount);
        $probability = self::decimal(max(0.0, min(100.0, (float) $probabilityPercent)));

        // bcmul with 6 digits then round half up to 2 decimals.
        $product = bcdiv(bcmul($amount, $probability, 6), '100', 6);

        return self::round2($product);
    }

    public static function round2(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $abs = $negative ? substr($value, 1) : $value;
        $rounded = bcadd($abs, '0.005', 6);
        $rounded = bcadd(self::truncate($rounded), '0', 2);

        return $negative && bccomp($rounded, '0', 2) !== 0 ? '-'.$rounded : $rounded;
    }

    public static function decimal(string|int|float $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }

    private static function truncate(string $value): string
    {
        $position = strpos($value, '.');

        return $position === false ? $value : substr($value, 0, $position + 3);
    }
}
