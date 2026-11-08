<?php

declare(strict_types=1);

namespace Modules\CRM\Services\PartyMaster;

/**
 * Canonical forms of the keys that identify a party (VAT number, fiscal code, email, phone). Users type them with
 * spaces, dots, country prefixes and mixed case, so duplicates are only found when every comparison goes through the
 * same normalisation.
 */
final class PartyKeyNormalizer
{
    /**
     * Uppercase alphanumerics, without the two-letter EU country prefix (IT01234567890 and 01234567890 are the same
     * VAT number). Swiss UIDs (CHE-123.456.789) keep their three-letter prefix.
     */
    public static function vat(?string $value): ?string
    {
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $value));

        if ($clean === '') {
            return null;
        }

        if (preg_match('/^[A-Z]{2}(\d{8,})$/', $clean, $matches) === 1) {
            return $matches[1];
        }

        return $clean;
    }

    /**
     * Uppercase alphanumerics: Italian fiscal codes are case-insensitive and often typed with spaces.
     */
    public static function fiscalCode(?string $value): ?string
    {
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $value));

        return $clean === '' ? null : $clean;
    }

    /**
     * Lowercase and trimmed: mailbox names are treated case-insensitively by every provider in practice.
     */
    public static function email(?string $value): ?string
    {
        $clean = strtolower(trim((string) $value));

        return $clean === '' || ! str_contains($clean, '@') ? null : $clean;
    }

    /**
     * Digits only, without the international prefix (00 or +) and without the Italian/Swiss country code, so that
     * "+39 02 1234567", "0039021234567" and "02 1234567" compare equal. Numbers shorter than six digits are not
     * reliable identifiers and are ignored.
     */
    public static function phone(?string $value): ?string
    {
        $raw = trim((string) $value);
        $digits = (string) preg_replace('/\D/', '', $raw);
        $international = str_starts_with($raw, '+') || str_starts_with($digits, '00');

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if ($international && (str_starts_with($digits, '39') || str_starts_with($digits, '41'))) {
            $digits = substr($digits, 2);
        }

        $digits = ltrim($digits, '0') === '' ? '' : $digits;

        return strlen($digits) < 6 ? null : $digits;
    }

    /**
     * Lowercase name without punctuation, legal-form suffix and repeated spaces, used as a weak match key only.
     */
    public static function name(?string $value): ?string
    {
        // Dots and apostrophes are dropped (S.r.l. -> srl), every other punctuation separates words.
        $clean = (string) preg_replace('/[.\']/u', '', (string) $value);
        $clean = mb_strtolower(trim((string) preg_replace('/[^\pL\pN ]+/u', ' ', $clean)));
        $clean = (string) preg_replace('/\b(srl|srls|spa|sas|snc|sa|sagl|gmbh|ag|ltd|llc|inc)\b/u', '', $clean);
        $clean = trim((string) preg_replace('/\s+/', ' ', $clean));

        return $clean === '' ? null : $clean;
    }
}
