<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Support;

/**
 * Formats amounts for the documents: Italian (1.234,56) or Swiss (1'234.56) separators, a minus sign for negatives.
 */
final class AmountFormatter
{
    public static function format(string $amount, int $scale, string $jurisdiction): string
    {
        $rounded = Money::round($amount, $scale);
        $negative = Money::isNegative($rounded);
        $absolute = $negative ? Money::neg($rounded) : $rounded;
        [$thousands, $decimal] = $jurisdiction === 'CH' ? ["'", '.'] : ['.', ','];
        $text = number_format((float) Money::round($absolute, $scale), $scale, $decimal, $thousands);

        return ($negative ? '-' : '').$text;
    }
}
