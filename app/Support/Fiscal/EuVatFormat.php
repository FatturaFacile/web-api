<?php

declare(strict_types=1);

namespace App\Support\Fiscal;

/**
 * Formal (offline) syntax of the VAT numbers of the EU Member States, as published by the European Commission.
 * Greece uses the prefix EL in VIES and Intrastat (ISO 3166 is GR); Northern Ireland (XI) is covered for goods.
 */
final class EuVatFormat
{
    /** Pattern of the national part (without the country prefix) by VIES country code. */
    private const array PATTERNS = [
        'AT' => 'U\d{8}',
        'BE' => '[01]\d{9}',
        'BG' => '\d{9,10}',
        'CY' => '\d{8}[A-Z]',
        'CZ' => '\d{8,10}',
        'DE' => '\d{9}',
        'DK' => '\d{8}',
        'EE' => '\d{9}',
        'EL' => '\d{9}',
        'ES' => '[A-Z0-9]\d{7}[A-Z0-9]',
        'FI' => '\d{8}',
        'FR' => '[A-Z0-9]{2}\d{9}',
        'HR' => '\d{11}',
        'HU' => '\d{8}',
        'IE' => '\d[A-Z0-9+*]\d{5}[A-Z]{1,2}',
        'IT' => '\d{11}',
        'LT' => '(\d{9}|\d{12})',
        'LU' => '\d{8}',
        'LV' => '\d{11}',
        'MT' => '\d{8}',
        'NL' => '\d{9}B\d{2}',
        'PL' => '\d{10}',
        'PT' => '\d{9}',
        'RO' => '\d{2,10}',
        'SE' => '\d{12}',
        'SI' => '\d{8}',
        'SK' => '\d{10}',
        'XI' => '(\d{9}|\d{12}|(GD|HA)\d{3})',
    ];

    /**
     * Maps an ISO 3166-1 alpha-2 code to the VIES / Intrastat one (GR becomes EL).
     */
    public static function toViesCountry(string $country): string
    {
        $country = strtoupper(trim($country));

        return $country === 'GR' ? 'EL' : $country;
    }

    public static function isMemberState(string $country): bool
    {
        return isset(self::PATTERNS[self::toViesCountry($country)]);
    }

    /**
     * @return list<string>
     */
    public static function memberStates(): array
    {
        return array_keys(self::PATTERNS);
    }

    /**
     * Strips spaces, dots, dashes and slashes and upper-cases the value.
     */
    public static function clean(string $value): string
    {
        return strtoupper((string) preg_replace('/[\s.\-\/]/', '', $value));
    }

    /**
     * Splits "DE 123 456 789" (or "123456789" with a known country) into [country, national number]. Returns null when
     * the country cannot be determined.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function split(string $value, ?string $country = null): ?array
    {
        $value = self::clean($value);

        if ($country !== null && $country !== '') {
            $country = self::toViesCountry($country);
            if (str_starts_with($value, $country)) {
                $value = substr($value, strlen($country));
            } elseif ($country === 'EL' && str_starts_with($value, 'GR')) {
                $value = substr($value, 2);
            }

            return [$country, $value];
        }

        if (strlen($value) < 3) {
            return null;
        }

        return [self::toViesCountry(substr($value, 0, 2)), substr($value, 2)];
    }

    public static function isValid(string $country, string $number): bool
    {
        $country = self::toViesCountry($country);

        return isset(self::PATTERNS[$country]) && preg_match('/^'.self::PATTERNS[$country].'$/', $number) === 1;
    }
}
