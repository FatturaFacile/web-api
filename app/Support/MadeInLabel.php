<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Derives the "Made in ..." marketing label from an ISO 3166-1 alpha-2 country code.
 */
final class MadeInLabel
{
    public static function fromCountry(string $countryCode): string
    {
        $code = strtoupper($countryCode);

        return match ($code) {
            'CH' => 'Swiss Made',
            'IT' => 'Made in Italy',
            'DE' => 'Made in Germany',
            'FR' => 'Made in France',
            default => 'Made in '.self::countryName($code),
        };
    }

    private static function countryName(string $code): string
    {
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('-'.$code, 'en');
            if ($name !== '' && $name !== $code) {
                return $name;
            }
        }

        return $code;
    }
}
