<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Translation;
use App\Support\RegionalTranslations;
use Database\Seeders\FrontendTranslationSeeder;
use Database\Seeders\TranslationSeeder;
use Illuminate\Console\Command;
use ReflectionMethod;

/**
 * Prints the seeded global dictionary of a locale as JSON, without touching the database.
 * The frontend specs use it to check their keys against the only copy of the UI strings.
 */
class DumpTranslations extends Command
{
    protected $signature = 'translations:dump {locale : it, en, de, fr or a regional variant: it-CH, de-CH, fr-CH, it-IT}';

    protected $description = 'Print the seeded global translations of a locale as JSON';

    private const GETTERS = [
        'it' => 'getItalianTranslations',
        'en' => 'getEnglishTranslations',
        'de' => 'getGermanTranslations',
        'fr' => 'getFrenchTranslations',
    ];

    public function handle(): int
    {
        $locale = (string) $this->argument('locale');
        $base = Translation::baseLocale($locale);
        if (! isset(self::GETTERS[$base]) || ($base !== $locale && ! array_key_exists($locale, Translation::REGIONAL_LOCALES))) {
            $this->error('Unknown locale: '.$locale);

            return self::FAILURE;
        }

        $method = new ReflectionMethod(TranslationSeeder::class, self::GETTERS[$base]);
        $method->setAccessible(true);

        $dictionary = RegionalTranslations::derive($locale, [...$method->invoke(new TranslationSeeder), ...FrontendTranslationSeeder::dictionary($base)]);
        $this->line(json_encode($dictionary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
