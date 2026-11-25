<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Support;

/**
 * Texts stored as a {it, en, de, fr} map: picks the one of the request language (falling back to English, Italian, then
 * any) and fills the {placeholders} of a message with the values of the configuration.
 */
final class Localized
{
    /** @var list<string> */
    public const array Locales = ['it', 'en', 'de', 'fr'];

    public static function pick(mixed $map, ?string $locale = null): string
    {
        if (is_string($map)) {
            return $map;
        }

        if (! is_array($map) || $map === []) {
            return '';
        }

        $locale ??= app()->getLocale();
        $short = strtolower(substr($locale, 0, 2));

        foreach ([$short, 'en', 'it'] as $candidate) {
            if (isset($map[$candidate]) && is_string($map[$candidate]) && $map[$candidate] !== '') {
                return $map[$candidate];
            }
        }

        foreach ($map as $text) {
            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    public static function interpolate(string $text, array $variables): string
    {
        return (string) preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_.]*)\}/', static function (array $match) use ($variables): string {
            $value = $variables[$match[1]] ?? null;

            return match (true) {
                is_bool($value) => $value ? 'yes' : 'no',
                is_int($value), is_float($value) => rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.'),
                is_string($value) => $value,
                default => $match[0],
            };
        }, $text);
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    public static function message(mixed $map, array $variables, ?string $locale = null): string
    {
        return self::interpolate(self::pick($map, $locale), $variables);
    }
}
