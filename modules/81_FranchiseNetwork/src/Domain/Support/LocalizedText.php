<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Support;

/**
 * Picks the text of a seeded multilingual label (`{it, en, de, fr}`) for a locale: exact locale, its language (it-CH -> it), English,
 * then whatever exists. The labels are seed data; no wording lives in code.
 */
final class LocalizedText
{
    /**
     * @param  array<string, string>|null  $labels
     */
    public static function pick(?array $labels, string $locale): string
    {
        if ($labels === null || $labels === []) {
            return '';
        }

        $language = strtolower(substr(str_replace('_', '-', $locale), 0, 2));

        return (string) ($labels[$locale] ?? $labels[$language] ?? $labels['en'] ?? reset($labels));
    }
}
