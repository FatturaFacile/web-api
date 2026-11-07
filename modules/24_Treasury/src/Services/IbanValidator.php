<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

/**
 * IBAN check of ISO 13616: alphabet, country, length of the country, and the mod-97 check digits. It proves the number
 * is well formed, not that the account exists.
 */
final class IbanValidator
{
    /**
     * Length of the IBAN per country, from the SWIFT IBAN registry.
     *
     * @var array<string, int>
     */
    private const array CountryLengths = [
        'AD' => 24, 'AE' => 23, 'AL' => 28, 'AT' => 20, 'AZ' => 28, 'BA' => 20, 'BE' => 16, 'BG' => 22, 'BH' => 22,
        'BR' => 29, 'BY' => 28, 'CH' => 21, 'CR' => 22, 'CY' => 28, 'CZ' => 24, 'DE' => 22, 'DK' => 18, 'DO' => 28,
        'EE' => 20, 'EG' => 29, 'ES' => 24, 'FI' => 18, 'FO' => 18, 'FR' => 27, 'GB' => 22, 'GE' => 22, 'GI' => 23,
        'GL' => 18, 'GR' => 27, 'GT' => 28, 'HR' => 21, 'HU' => 28, 'IE' => 22, 'IL' => 23, 'IQ' => 23, 'IS' => 26,
        'IT' => 27, 'JO' => 30, 'KW' => 30, 'KZ' => 20, 'LB' => 28, 'LC' => 32, 'LI' => 21, 'LT' => 20, 'LU' => 20,
        'LV' => 21, 'LY' => 25, 'MC' => 27, 'MD' => 24, 'ME' => 22, 'MK' => 19, 'MR' => 27, 'MT' => 31, 'MU' => 30,
        'NL' => 18, 'NO' => 15, 'PK' => 24, 'PL' => 28, 'PS' => 29, 'PT' => 25, 'QA' => 29, 'RO' => 24, 'RS' => 22,
        'SA' => 24, 'SC' => 31, 'SD' => 18, 'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27, 'ST' => 25, 'SV' => 28,
        'TL' => 23, 'TN' => 24, 'TR' => 26, 'UA' => 29, 'VA' => 22, 'VG' => 24, 'XK' => 20,
    ];

    public function normalize(string $iban): string
    {
        return strtoupper(str_replace([' ', '-'], '', $iban));
    }

    public function isValid(string $iban): bool
    {
        return $this->check($iban)['is_valid'];
    }

    /**
     * @return array{iban: string, is_valid: bool, country: ?string, reason: ?string}
     */
    public function check(string $iban): array
    {
        $normalized = $this->normalize($iban);
        $country = substr($normalized, 0, 2);
        $reason = match (true) {
            preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $normalized) !== 1 => 'invalid_format',
            ! isset(self::CountryLengths[$country]) => 'unknown_country',
            strlen($normalized) !== self::CountryLengths[$country] => 'invalid_length',
            $this->remainder($normalized) !== 1 => 'invalid_checksum',
            default => null,
        };

        return [
            'iban' => $normalized,
            'is_valid' => $reason === null,
            'country' => isset(self::CountryLengths[$country]) ? $country : null,
            'reason' => $reason,
        ];
    }

    /**
     * Moves the first four characters to the end, turns letters into 10 to 35 and takes the number modulo 97, one digit
     * at a time because the number does not fit an integer.
     */
    private function remainder(string $iban): int
    {
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $remainder = 0;

        foreach (str_split($rearranged) as $character) {
            foreach (str_split((string) (ctype_alpha($character) ? ord($character) - 55 : (int) $character)) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder;
    }
}
