<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Services;

use Database\Seeders\Translations\LiquidityPlanningTranslations;

/**
 * The texts the API itself produces (alerts, notification emails, report and export labels). They are not language files: they
 * come from the seeded dictionary `LiquidityPlanningTranslations`, the same one the web client reads (keys `lpApi.*`), so a
 * text is written once in the four locales.
 */
final class LiquidityTexts
{
    /** @var array<string, array<string, string>> */
    private static array $dictionaries = [];

    /**
     * @param  array<string, mixed>  $params  values replacing the {name} placeholders
     */
    public static function get(string $key, string $locale, array $params = []): string
    {
        $locale = in_array($locale, ['it', 'en', 'de', 'fr'], true) ? $locale : 'en';
        self::$dictionaries[$locale] ??= LiquidityPlanningTranslations::all($locale);
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
