<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\TranslationSeeder;
use Illuminate\Console\Command;
use ReflectionMethod;

/**
 * Prints the seeded global dictionary of a locale as JSON, without touching the database.
 * The frontend specs use it to check their keys against the only copy of the UI strings.
 */
class DumpTranslations extends Command
{
    protected $signature = 'translations:dump {locale : it, en, de or fr}';

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
        if (! isset(self::GETTERS[$locale])) {
            $this->error('Unknown locale: '.$locale);

            return self::FAILURE;
        }

        $method = new ReflectionMethod(TranslationSeeder::class, self::GETTERS[$locale]);
        $method->setAccessible(true);

        $this->line(json_encode($method->invoke(new TranslationSeeder), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
