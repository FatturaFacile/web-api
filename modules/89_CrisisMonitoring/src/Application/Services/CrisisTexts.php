<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Application\Services;

use Database\Seeders\Translations\CrisisMonitoringTranslations;

/**
 * The texts the API itself produces (the dossier and agreement PDFs, messages). They are not language files: they come from the seeded
 * dictionary `CrisisMonitoringTranslations` (keys `crisisApi.*`), the same one the web client reads, so a text is written once in the
 * four locales.
 */
final class CrisisTexts
{
    /** @var array<string, array<string, string>> */
    private static array $dictionaries = [];

    /**
     * @param  array<string, mixed>  $params  values replacing the {name} placeholders
     */
    public static function get(string $key, string $locale, array $params = []): string
    {
        $locale = self::locale($locale);
        self::$dictionaries[$locale] ??= CrisisMonitoringTranslations::all($locale);
        $text = self::$dictionaries[$locale][$key] ?? $key;

        foreach ($params as $name => $value) {
            if (is_scalar($value) || $value === null) {
                $text = str_replace('{'.$name.'}', (string) $value, $text);
            }
        }

        return $text;
    }

    public static function locale(?string $requested = null): string
    {
        $locale = $requested ?: app()->getLocale();

        return in_array($locale, ['it', 'en', 'de', 'fr'], true) ? $locale : 'en';
    }
}
