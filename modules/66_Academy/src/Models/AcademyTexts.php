<?php

declare(strict_types=1);

namespace Modules\Academy\Models;

use Modules\Academy\Domain\Enums\AcademyLocale;

/**
 * Picks a translated text from a locale-keyed map following the fallback chain of the requested locale.
 */
final class AcademyTexts
{
    /**
     * @param  array<string, array<string, string>>  $translations
     */
    public static function pick(array $translations, string $field, AcademyLocale $locale): string
    {
        foreach ($locale->fallbackChain() as $candidate) {
            $text = $translations[$candidate->value][$field] ?? null;

            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        return '';
    }
}
