<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Domain\Support;

/**
 * Country knowledge of the module: the currency of the refund country and the country a VAT number belongs to.
 */
final class Jurisdictions
{
    /** @var array<string, string> ISO country code => currency of the refund application */
    public const array CURRENCIES = [
        'CH' => 'CHF', 'IT' => 'EUR', 'DE' => 'EUR', 'FR' => 'EUR', 'AT' => 'EUR', 'ES' => 'EUR', 'NL' => 'EUR', 'BE' => 'EUR',
        'PT' => 'EUR', 'LU' => 'EUR', 'IE' => 'EUR', 'FI' => 'EUR', 'GR' => 'EUR', 'SI' => 'EUR', 'SK' => 'EUR', 'EE' => 'EUR',
        'LV' => 'EUR', 'LT' => 'EUR', 'MT' => 'EUR', 'CY' => 'EUR', 'HR' => 'EUR', 'DK' => 'DKK', 'SE' => 'SEK', 'PL' => 'PLN',
        'CZ' => 'CZK', 'HU' => 'HUF', 'RO' => 'RON', 'BG' => 'BGN', 'GB' => 'GBP',
    ];

    /** @var list<string> */
    public const array EU_MEMBER_STATES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    public static function currencyOf(string $country): ?string
    {
        return self::CURRENCIES[strtoupper($country)] ?? null;
    }

    public static function isEuMemberState(string $country): bool
    {
        return in_array(strtoupper($country), self::EU_MEMBER_STATES, true);
    }

    /**
     * The country a VAT identification number belongs to, from its prefix (CHE-123.456.789 MWST, DE123456789, EL123456789);
     * null when the value carries no recognisable prefix.
     */
    public static function countryOfVatNumber(?string $vatNumber): ?string
    {
        $value = strtoupper(preg_replace('/[\s.\-]/', '', (string) $vatNumber) ?? '');

        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'CHE')) {
            return 'CH';
        }

        $prefix = substr($value, 0, 2);

        if ($prefix === 'EL') {
            return 'GR';
        }

        if ($prefix === 'XI') {
            return 'GB';
        }

        return preg_match('/^[A-Z]{2}[A-Z0-9]{2,}$/', $value) === 1 && isset(self::CURRENCIES[$prefix]) ? $prefix : null;
    }

    /**
     * Quarter (1-4) of a date given as Y-m-d.
     */
    public static function quarterOf(string $date): int
    {
        return (int) ceil(((int) substr($date, 5, 2)) / 3);
    }
}
