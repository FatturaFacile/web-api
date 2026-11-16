<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\Payroll;

use InvalidArgumentException;

/**
 * Exact conversions between decimal strings and the integer units used by the payroll engine:
 * CHF amounts as Rappen (1 CHF = 100 Rappen) and percentages as parts per million (1% = 10'000 ppm).
 * No floating point arithmetic is involved: values are parsed digit by digit.
 */
final class Rappen
{
    public const PPM_PER_UNIT = 1_000_000;

    public static function fromDecimal(string|int|float $amount): int
    {
        return self::parseScaled($amount, 2);
    }

    public static function toDecimal(int $rappen): string
    {
        $sign = $rappen < 0 ? '-' : '';
        $absolute = abs($rappen);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }

    /**
     * Converts a percentage ("5.30", "1.025") into parts per million (53000, 10250).
     */
    public static function percentToPpm(string|int|float $percent): int
    {
        return self::parseScaled($percent, 4);
    }

    public static function ppmToPercent(int $ppm): string
    {
        $sign = $ppm < 0 ? '-' : '';
        $absolute = abs($ppm);
        $decimals = rtrim(sprintf('%04d', $absolute % 10_000), '0');

        return $sign.intdiv($absolute, 10_000).($decimals === '' ? '' : '.'.$decimals);
    }

    /**
     * Parses a decimal value into an integer scaled by 10^$scale, rounding half away from zero on the first
     * dropped digit.
     */
    private static function parseScaled(string|int|float $value, int $scale): int
    {
        if (is_int($value)) {
            return $value * (10 ** $scale);
        }

        $normalized = is_float($value)
            ? number_format($value, $scale + 2, '.', '')
            : trim($value);

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $normalized, $matches) !== 1) {
            throw new InvalidArgumentException("'{$normalized}' is not a valid decimal number.");
        }

        $negative = $matches[1] === '-';
        $integerPart = (int) $matches[2];
        $fraction = $matches[3] ?? '';
        $kept = str_pad(substr($fraction, 0, $scale), $scale, '0');
        $roundingDigit = (int) ($fraction[$scale] ?? '0');

        $scaled = $integerPart * (10 ** $scale) + (int) $kept;

        if ($roundingDigit >= 5) {
            $scaled++;
        }

        return $negative ? -$scaled : $scaled;
    }
}
