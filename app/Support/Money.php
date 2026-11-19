<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use OverflowException;

/**
 * Integer-cent money helpers for the Italian fiscal calculation. Amounts are handled as integers (cents, or a
 * fixed number of decimals) and every rounding is half-up (away from zero), so no float ever reaches a total.
 */
final class Money
{
    /**
     * Convert a decimal amount (string, int or float) to integer cents, rounding half-up.
     */
    public static function toCents(string|int|float|null $amount): int
    {
        return self::toScaled($amount, 2);
    }

    /**
     * Convert a decimal value to an integer with `$scale` implied decimals, rounding half-up.
     * Example: toScaled('22.5', 2) = 2250; toScaled('1.23456', 4) = 12346.
     */
    public static function toScaled(string|int|float|null $value, int $scale): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_int($value)) {
            return self::multiply($value, 10 ** $scale);
        }

        // Scientific notation ("1e3", accepted by the numeric validation rule) is read as a float.
        if (is_string($value) && is_numeric($value) && stripos($value, 'e') !== false) {
            $value = (float) $value;
        }

        // Floats are first written with 8 decimals so the binary noise of values like 0.1 or 1.005 disappears.
        $string = is_float($value) ? sprintf('%.8F', $value) : trim($value);

        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $string, $matches) !== 1 || ($matches[2] === '' && ($matches[3] ?? '') === '')) {
            throw new InvalidArgumentException("Invalid decimal amount [{$string}].");
        }

        $negative = $matches[1] === '-';
        $integerPart = ltrim($matches[2], '0');
        $fraction = $matches[3] ?? '';

        $kept = str_pad(substr($fraction, 0, $scale), $scale, '0');
        $roundUp = strlen($fraction) > $scale && (int) $fraction[$scale] >= 5;

        $digits = ltrim(($integerPart === '' ? '0' : $integerPart).$kept, '0');

        if (strlen($digits) > 18) {
            throw new OverflowException("Amount [{$string}] is too large.");
        }

        $result = (int) ($digits === '' ? '0' : $digits);

        if ($roundUp) {
            $result++;
        }

        return $negative ? -$result : $result;
    }

    /**
     * Format integer cents as a decimal string with two decimals ("1068.80", "-0.05").
     */
    public static function fromCents(int $cents): string
    {
        return self::fromScaled($cents, 2);
    }

    /**
     * Format a scaled integer as a decimal string with `$scale` decimals.
     */
    public static function fromScaled(int $value, int $scale): string
    {
        $sign = $value < 0 ? '-' : '';
        $absolute = (string) abs($value);

        if ($scale === 0) {
            return $sign.$absolute;
        }

        $absolute = str_pad($absolute, $scale + 1, '0', STR_PAD_LEFT);

        return $sign.substr($absolute, 0, -$scale).'.'.substr($absolute, -$scale);
    }

    /**
     * A percentage of an amount in cents, rounded half-up. The percentage is read with two decimals (22.00, 4.5).
     */
    public static function percentOf(int $cents, string|int|float $percent): int
    {
        return self::percentOfBasisPoints($cents, self::toScaled($percent, 2));
    }

    /**
     * A percentage, given in hundredths of a percent (2200 = 22.00%), of an amount in cents, rounded half-up.
     */
    public static function percentOfBasisPoints(int $cents, int $basisPoints): int
    {
        return self::divideRounded(self::multiply($cents, $basisPoints), 10000);
    }

    /**
     * Multiply two scaled integers and return the product in cents, rounded half-up
     * (e.g. quantity with 4 decimals x unit price with 4 decimals).
     */
    public static function multiplyToCents(int $left, int $leftScale, int $right, int $rightScale): int
    {
        $productScale = $leftScale + $rightScale;
        $product = self::multiply($left, $right);

        if ($productScale <= 2) {
            return self::multiply($product, 10 ** (2 - $productScale));
        }

        return self::divideRounded($product, 10 ** ($productScale - 2));
    }

    /**
     * Integer division rounded half-up (away from zero).
     */
    public static function divideRounded(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        $negative = ($numerator < 0) !== ($denominator < 0);
        $numerator = abs($numerator);
        $denominator = abs($denominator);

        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;

        if ($remainder * 2 >= $denominator) {
            $quotient++;
        }

        return $negative ? -$quotient : $quotient;
    }

    /**
     * Overflow-checked integer multiplication.
     */
    private static function multiply(int $left, int $right): int
    {
        if ($left === 0 || $right === 0) {
            return 0;
        }

        if ($left === 1) {
            return $right;
        }

        if ($right === 1) {
            return $left;
        }

        if ($left === PHP_INT_MIN || $right === PHP_INT_MIN || abs($right) > intdiv(PHP_INT_MAX, abs($left))) {
            throw new OverflowException('Amount is too large.');
        }

        return $left * $right;
    }
}
