<?php

declare(strict_types=1);

namespace Modules\CRM\Support;

use Illuminate\Support\Str;

/**
 * Normalisation helpers used to compare leads: E.164 phones, VAT numbers and company names.
 */
final class LeadNormalizer
{
    private const LEGAL_FORMS = [
        'spa', 'srl', 'srls', 'snc', 'sas', 'sapa', 'sagl', 'ag', 'gmbh', 'sa', 'sarl', 'ltd', 'llc', 'inc', 'co', 'ug', 'kg', 'ss', 'scarl',
    ];

    public static function email(?string $email): ?string
    {
        $email = $email === null ? '' : strtolower(trim($email));

        return $email === '' ? null : $email;
    }

    /**
     * Converts a free-form phone number to E.164 (+<country><number>), using the lead country as default prefix.
     */
    public static function phoneE164(?string $phone, string $country = 'IT'): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $raw = trim($phone);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($raw, '+')) {
            $e164 = $digits;
        } elseif (str_starts_with($digits, '00')) {
            $e164 = substr($digits, 2);
        } else {
            $dial = (string) config('crm_lead.dial_codes.'.strtoupper($country), '39');
            $national = ltrim($digits, '0');
            // Italian landlines keep their leading zero; other countries drop the trunk prefix.
            if ($dial === '39' && str_starts_with($digits, '0')) {
                $national = $digits;
            }
            $e164 = str_starts_with($digits, $dial) && strlen($digits) > 10 ? $digits : $dial.$national;
        }

        return strlen($e164) >= 7 && strlen($e164) <= 15 ? '+'.$e164 : null;
    }

    /**
     * Upper-cases a VAT number and strips spaces, punctuation and the optional country prefix (IT, CHE, DE...).
     */
    public static function vat(?string $vat): ?string
    {
        if ($vat === null) {
            return null;
        }

        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vat) ?? '');
        $clean = preg_replace('/^(IT|CHE|DE|FR|AT|ES|NL|BE|GB)(?=\d)/', '', $clean) ?? $clean;
        $clean = preg_replace('/(MWST|TVA|IVA)$/', '', $clean) ?? $clean;

        return $clean === '' ? null : $clean;
    }

    /**
     * Lower-cases, strips accents, punctuation and legal forms (S.p.A., Srl, GmbH...) from a company name.
     */
    public static function company(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $ascii = strtolower(Str::ascii($name));
        $ascii = str_replace(['.', ',', "'", '"', '&'], ['', ' ', '', '', ' and '], $ascii);
        $tokens = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_filter($tokens, fn (string $t): bool => ! in_array($t, self::LEGAL_FORMS, true)));
        $normalized = implode(' ', $tokens);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Similarity between two normalized strings in the 0-1 range, based on the Levenshtein distance.
     */
    public static function similarity(string $a, string $b): float
    {
        $a = substr($a, 0, 250);
        $b = substr($b, 0, 250);
        $max = max(strlen($a), strlen($b));

        return $max === 0 ? 1.0 : 1.0 - (levenshtein($a, $b) / $max);
    }
}
