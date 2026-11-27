<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Support;

/**
 * SIX Paynet Biller ID: 17 digits (a leading "41" for Switzerland) whose last digit is the Luhn (mod 10) check digit of the
 * first sixteen. The checksum is the platform's own sanity check against typing errors; the gateway stays the authority.
 */
final class BillerIdValidator
{
    public static function normalize(string $value): string
    {
        return (string) preg_replace('/[\s.\-]/', '', $value);
    }

    public static function isValid(string $value): bool
    {
        $id = self::normalize($value);

        return preg_match('/^41\d{15}$/', $id) === 1 && self::luhn($id);
    }

    /**
     * The check digit that completes sixteen digits (used by the sandbox and the tests to build valid ids).
     */
    public static function checkDigit(string $sixteenDigits): int
    {
        return (10 - self::luhnSum($sixteenDigits.'0') % 10) % 10;
    }

    private static function luhn(string $digits): bool
    {
        return self::luhnSum($digits) % 10 === 0;
    }

    private static function luhnSum(string $digits): int
    {
        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;
                $digit = $digit > 9 ? $digit - 9 : $digit;
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum;
    }
}
