<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Domain\Support;

/**
 * Locale handling of the survey texts: the five supported locales it-CH, de-CH, fr-CH, en and it-IT, resolved with a fallback to
 * the language and then to English.
 */
final class SurveyLocale
{
    /** @var list<string> */
    public const array Supported = ['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT'];

    public static function normalize(?string $locale): string
    {
        $locale = str_replace('_', '-', trim((string) $locale));

        foreach (self::Supported as $supported) {
            if (strcasecmp($supported, $locale) === 0) {
                return $supported;
            }
        }

        return match (strtolower(substr($locale, 0, 2))) {
            'it' => 'it-IT',
            'de' => 'de-CH',
            'fr' => 'fr-CH',
            default => 'en',
        };
    }

    public static function forCountry(?string $country): string
    {
        return match (strtoupper((string) $country)) {
            'IT' => 'it-IT',
            'CH' => 'it-CH',
            'DE', 'AT' => 'de-CH',
            'FR' => 'fr-CH',
            default => 'en',
        };
    }

    public static function language(string $locale): string
    {
        return strtolower(substr($locale, 0, 2));
    }

    /**
     * @template T
     *
     * @param  array<string, T>|null  $texts  keyed by locale
     * @return T|null
     */
    public static function pick(?array $texts, string $locale)
    {
        if ($texts === null || $texts === []) {
            return null;
        }

        $language = self::language($locale);

        foreach ([$locale, $language] as $candidate) {
            if (array_key_exists($candidate, $texts)) {
                return $texts[$candidate];
            }
        }

        // Another variant of the same language (it-IT reads the it-CH text) comes before the English fallback.
        foreach ($texts as $key => $text) {
            if (self::language((string) $key) === $language) {
                return $text;
            }
        }

        return $texts['en'] ?? reset($texts);
    }
}
