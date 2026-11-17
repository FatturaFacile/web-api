<?php

declare(strict_types=1);

namespace App\Services\Crm\Fiscal;

/**
 * Formal (offline) validation and normalisation of Italian and Swiss fiscal identifiers.
 */
final class FiscalIdentifiers
{
    private const CF_ODD = [1, 0, 5, 7, 9, 13, 15, 17, 19, 21, 2, 4, 18, 20, 11, 3, 6, 8, 12, 14, 16, 10, 22, 25, 24, 23];

    /** Letters that replace digits in "omocodia" fiscal codes (0..9 => L M N P Q R S T U V). */
    private const OMOCODIA = 'LMNPQRSTUV';

    /** Zero-based positions of a fiscal code that hold digits and may be replaced by omocodia letters. */
    private const DIGIT_POSITIONS = [6, 7, 9, 10, 12, 13, 14];

    /**
     * Strips the optional "IT" prefix, spaces, dots and dashes.
     */
    public static function normalizeItalianVat(string $value): string
    {
        $value = strtoupper((string) preg_replace('/[\s.\-]/', '', $value));

        return str_starts_with($value, 'IT') ? substr($value, 2) : $value;
    }

    /**
     * Italian Partita IVA: 11 digits, Luhn-like check digit.
     */
    public static function isValidItalianVat(string $value): bool
    {
        $vat = self::normalizeItalianVat($value);

        if (! preg_match('/^\d{11}$/', $vat) || (int) substr($vat, 0, 7) === 0) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $digit = (int) $vat[$i];
            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return (10 - $sum % 10) % 10 === (int) $vat[10];
    }

    public static function normalizeFiscalCode(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $value));
    }

    /**
     * Italian Codice Fiscale: either the 16-character personal code (checksum letter, omocodia aware, plausible
     * birth date) or the 11-digit code of legal entities, which follows the Partita IVA algorithm.
     */
    public static function isValidItalianFiscalCode(string $value): bool
    {
        $code = self::normalizeFiscalCode($value);

        if (preg_match('/^\d{11}$/', $code)) {
            return self::isValidItalianVat($code);
        }

        if (! preg_match('/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[ABCDEHLMPRST][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/', $code)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 15; $i++) {
            $char = $code[$i];
            $index = ctype_digit($char) ? (int) $char : ord($char) - ord('A');

            // Positions are 1-based in the specification: odd positions use the conversion table.
            $sum += $i % 2 === 0 ? self::CF_ODD[$index] : $index;
        }

        if (chr(ord('A') + $sum % 26) !== $code[15]) {
            return false;
        }

        return self::hasPlausibleBirthDate($code);
    }

    public static function normalizeSwissUid(string $value): ?string
    {
        if (! preg_match('/^\s*CHE[\s.\-]?(\d{3})[\s.\-]?(\d{3})[\s.\-]?(\d{3})(?:\s*(MWST|TVA|IVA|VAT|HR|RC|RF))?\s*$/i', $value, $m)) {
            return null;
        }

        return sprintf('CHE-%s.%s.%s', $m[1], $m[2], $m[3]);
    }

    /**
     * Swiss enterprise identification number (UID): CHE-xxx.xxx.xxx, modulo-11 check digit.
     */
    public static function isValidSwissUid(string $value): bool
    {
        $uid = self::normalizeSwissUid($value);

        if ($uid === null) {
            return false;
        }

        $digits = preg_replace('/\D/', '', $uid);
        $weights = [5, 4, 3, 2, 7, 6, 5, 4];
        $sum = 0;

        foreach ($weights as $i => $weight) {
            $sum += (int) $digits[$i] * $weight;
        }

        $check = 11 - $sum % 11;

        if ($check === 11) {
            $check = 0;
        }

        return $check !== 10 && $check === (int) $digits[8];
    }

    /**
     * SDI recipient code: 7 alphanumeric characters ("0000000" for PEC delivery, "XXXXXXX" for foreign customers).
     */
    public static function isValidSdiCode(string $value): bool
    {
        return (bool) preg_match('/^[A-Z0-9]{7}$/', strtoupper(trim($value)));
    }

    private static function hasPlausibleBirthDate(string $code): bool
    {
        $digits = self::resolveOmocodia($code);
        $month = strpos('ABCDEHLMPRST', $digits[8]);
        $day = (int) substr($digits, 9, 2);
        $day = $day > 40 ? $day - 40 : $day;
        $maxDays = [31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][$month];

        return $day >= 1 && $day <= $maxDays;
    }

    private static function resolveOmocodia(string $code): string
    {
        foreach (self::DIGIT_POSITIONS as $position) {
            $index = strpos(self::OMOCODIA, $code[$position]);

            if ($index !== false) {
                $code[$position] = (string) $index;
            }
        }

        return $code;
    }
}
