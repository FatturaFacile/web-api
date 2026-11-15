<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Illuminate\Support\Str;
use Modules\CRM\Contracts\LeadNormalizerInterface;

/**
 * In-house normalization of lead identifiers. Phone handling covers Italy, Switzerland and a small set of
 * neighbouring countries; swapping in libphonenumber later only requires changing normalizePhone().
 */
final class LeadNormalizer implements LeadNormalizerInterface
{
    /**
     * Country calling codes of the supported default countries.
     *
     * @var array<string, string>
     */
    private const array CALLING_CODES = [
        'IT' => '39',
        'SM' => '378',
        'VA' => '39',
        'CH' => '41',
        'LI' => '423',
        'DE' => '49',
        'AT' => '43',
        'FR' => '33',
        'ES' => '34',
        'BE' => '32',
        'NL' => '31',
        'LU' => '352',
        'GB' => '44',
        'US' => '1',
    ];

    /**
     * Countries whose national numbers keep the leading 0 after the country code.
     *
     * @var list<string>
     */
    private const array KEEPS_TRUNK_ZERO = ['IT', 'SM', 'VA'];

    /**
     * Legal-form tokens stripped from the end of company names.
     *
     * @var list<string>
     */
    private const array LEGAL_SUFFIXES = [
        'spa', 'srl', 'srls', 'sas', 'snc', 'sapa', 'scarl', 'scrl', 'coop',
        'sagl', 'ag', 'gmbh', 'sa', 'sarl', 'kg', 'ohg', 'gbr', 'ug',
        'ltd', 'limited', 'llc', 'inc', 'corp', 'co', 'plc', 'bv', 'nv',
    ];

    public function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = strtolower(trim($email));

        return $email !== '' ? $email : null;
    }

    public function normalizePhone(?string $phone, ?string $defaultCountry = null): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $raw = trim($phone);
        $hasPlus = str_starts_with($raw, '+');
        $digits = (string) preg_replace('/\D/', '', $raw);

        if ($digits === '') {
            return null;
        }

        if (! $hasPlus && str_starts_with($digits, '00')) {
            $hasPlus = true;
            $digits = substr($digits, 2);
        }

        if ($hasPlus) {
            return $this->validE164($digits);
        }

        $country = strtoupper(trim((string) $defaultCountry));
        if ($country === '') {
            $country = 'IT';
        }

        $callingCode = self::CALLING_CODES[$country] ?? null;
        if ($callingCode === null) {
            return null;
        }

        // Numbers typed with the country code but without "+" or "00" (e.g. 393331234567, 41791234567).
        if ($this->looksInternational($digits, $country, $callingCode)) {
            return $this->validE164($digits);
        }

        if (! in_array($country, self::KEEPS_TRUNK_ZERO, true)) {
            $digits = (string) preg_replace('/^0/', '', $digits);
        }

        return $this->validE164($callingCode.$digits);
    }

    public function normalizeVat(?string $vat): ?string
    {
        if ($vat === null) {
            return null;
        }

        $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $vat));

        // Swiss UID with VAT suffix: CHE-123.456.789 MWST/TVA/IVA -> CHE123456789
        if (preg_match('/^(CHE\d{9})(MWST|TVA|IVA)$/', $normalized, $matches) === 1) {
            $normalized = $matches[1];
        }

        return $normalized !== '' ? $normalized : null;
    }

    public function vatVariants(?string $vat): array
    {
        $normalized = $this->normalizeVat($vat);
        if ($normalized === null) {
            return [];
        }

        $variants = [$normalized];

        if (preg_match('/^IT(\d{11})$/', $normalized, $matches) === 1) {
            $variants[] = $matches[1];
        } elseif (preg_match('/^\d{11}$/', $normalized) === 1) {
            $variants[] = 'IT'.$normalized;
        } elseif (preg_match('/^CHE\d{9}$/', $normalized) === 1) {
            // Stored Swiss numbers often keep the VAT register suffix.
            foreach (['MWST', 'TVA', 'IVA'] as $suffix) {
                $variants[] = $normalized.$suffix;
            }
        }

        return $variants;
    }

    public function normalizeCompanyName(?string $companyName): ?string
    {
        if ($companyName === null || trim($companyName) === '') {
            return null;
        }

        $name = Str::ascii($companyName);
        $name = strtolower($name);
        // Collapse dotted abbreviations first so "S.p.A." becomes "spa" rather than "s p a".
        $name = str_replace('.', '', $name);
        $name = (string) preg_replace('/[^a-z0-9]+/', ' ', $name);
        $tokens = array_values(array_filter(explode(' ', trim($name)), static fn (string $token): bool => $token !== ''));

        while (count($tokens) > 1 && in_array($tokens[count($tokens) - 1], self::LEGAL_SUFFIXES, true)) {
            array_pop($tokens);
        }

        $normalized = implode(' ', $tokens);

        return $normalized !== '' ? $normalized : null;
    }

    private function looksInternational(string $digits, string $country, string $callingCode): bool
    {
        if (! str_starts_with($digits, $callingCode)) {
            return false;
        }

        $national = substr($digits, strlen($callingCode));

        return match ($country) {
            // Italian national numbers start with 0 (landline) or 3 (mobile) and have 6-11 digits.
            'IT', 'SM', 'VA' => strlen($digits) >= 11 && preg_match('/^[03]\d{5,10}$/', $national) === 1,
            // Swiss national significant numbers have 9 digits and never start with 0.
            'CH' => strlen($national) === 9 && ! str_starts_with($national, '0'),
            default => strlen($digits) >= 11 && ! str_starts_with($national, '0'),
        };
    }

    private function validE164(string $digits): ?string
    {
        $length = strlen($digits);

        if ($length < 8 || $length > 15 || str_starts_with($digits, '0')) {
            return null;
        }

        return '+'.$digits;
    }
}
