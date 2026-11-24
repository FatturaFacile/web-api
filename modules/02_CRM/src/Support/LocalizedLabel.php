<?php

declare(strict_types=1);

namespace Modules\CRM\Support;

/**
 * Resolves the display label of a seeded catalog row (pipeline, stage, loss reason) from its per-locale translations.
 * The frontend never carries these strings: the API serves them already localized.
 */
final class LocalizedLabel
{
    /**
     * Locales the sales pipeline catalogs are seeded in.
     *
     * @var list<string>
     */
    public const array LOCALES = ['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT'];

    /**
     * @param  array<string, string>|null  $translations
     */
    public static function resolve(?array $translations, string $fallback, ?string $locale = null): string
    {
        $locale = str_replace('_', '-', $locale ?? app()->getLocale());
        $language = strtolower(explode('-', $locale)[0]);

        $candidates = array_values(array_unique([
            $locale,
            match ($language) {
                'it' => 'it-CH',
                'de' => 'de-CH',
                'fr' => 'fr-CH',
                default => $language,
            },
            $language === 'it' ? 'it-IT' : $language,
            $language,
            'en',
        ]));

        foreach ($candidates as $candidate) {
            $value = $translations[$candidate] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $fallback;
    }
}
