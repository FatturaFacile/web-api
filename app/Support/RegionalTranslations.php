<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Derives the dictionary of a regional variant (it-CH, de-CH, fr-CH, it-IT) from the dictionary of its language, so a
 * variant is complete from the first day and only differs where its spelling does. Swiss German has no sharp s.
 */
final class RegionalTranslations
{
    /**
     * @param  array<string, string>  $base  the dictionary of the language of the variant
     * @return array<string, string>
     */
    public static function derive(string $locale, array $base): array
    {
        if ($locale !== 'de-CH') {
            return $base;
        }

        return array_map(static fn (string $value): string => str_replace(['ß', 'ẞ'], ['ss', 'SS'], $value), $base);
    }
}
