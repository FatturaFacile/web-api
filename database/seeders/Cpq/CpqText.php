<?php

declare(strict_types=1);

namespace Database\Seeders\Cpq;

/**
 * Builds the {it, en, de, fr} text map stored in the CPQ columns from the four texts written in the seeders.
 */
final class CpqText
{
    /**
     * @return array{it: string, en: string, de: string, fr: string}
     */
    public static function t(string $it, string $en, string $de, string $fr): array
    {
        return ['it' => $it, 'en' => $en, 'de' => $de, 'fr' => $fr];
    }
}
