<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Support;

/**
 * Swiss enterprise identification number (UID): CHE-123.456.789 with the modulo 11 check digit (weights 5 4 3 2 7 6 5 4).
 * A VAT suffix (MWST / TVA / IVA) is accepted and dropped.
 */
final class SwissUid
{
    /**
     * @return string|null the canonical CHE-xxx.xxx.xxx form, null when the value is not a valid UID
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', (string) preg_replace('/(MWST|TVA|IVA|VAT)\s*$/i', '', strtoupper(trim($value))));

        if (! str_starts_with(strtoupper(trim($value)), 'CHE') || strlen($digits) !== 9 || ! self::checkDigitMatches($digits)) {
            return null;
        }

        return sprintf('CHE-%s.%s.%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 3));
    }

    public static function isValid(?string $value): bool
    {
        return self::normalize($value) !== null;
    }

    /**
     * The nine-digit body that completes eight digits with their check digit (used by the sandbox and the tests).
     */
    public static function complete(string $eightDigits): ?string
    {
        $check = self::checkDigit($eightDigits);

        return $check === null ? null : $eightDigits.$check;
    }

    private static function checkDigitMatches(string $nineDigits): bool
    {
        return self::checkDigit(substr($nineDigits, 0, 8)) === (int) $nineDigits[8];
    }

    private static function checkDigit(string $eightDigits): ?int
    {
        $weights = [5, 4, 3, 2, 7, 6, 5, 4];
        $sum = 0;

        foreach ($weights as $index => $weight) {
            $sum += (int) $eightDigits[$index] * $weight;
        }

        $check = 11 - ($sum % 11);

        return match ($check) {
            11 => 0,
            10 => null,
            default => $check,
        };
    }
}
