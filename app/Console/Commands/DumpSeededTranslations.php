<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Translation;
use App\Support\RegionalTranslations;
use Database\Seeders\FrontendTranslationSeeder;
use Database\Seeders\TranslationSeeder;
use Illuminate\Console\Command;

/**
 * Prints the global dictionary of one language (TranslationSeeder + FrontendTranslationSeeder) as JSON.
 *
 * The frontend has no copy of its UI strings: its checks read them from here, straight from the seeders and without
 * touching the database.
 */
class DumpSeededTranslations extends Command
{
    /**
     * @var string
     */
    protected $signature = 'translations:dump {locale : it, en, de, fr or a regional variant: it-CH, de-CH, fr-CH, it-IT}';

    /**
     * @var string
     */
    protected $description = 'Print the seeded global dictionary of a language as JSON (no database needed)';

    public function handle(): int
    {
        $locale = (string) $this->argument('locale');
        $base = Translation::baseLocale($locale);

        if (! in_array($base, TranslationSeeder::LOCALES, true) || ($base !== $locale && ! array_key_exists($locale, Translation::REGIONAL_LOCALES))) {
            $this->components->error('Unsupported locale. Use one of: '.implode(', ', [...TranslationSeeder::LOCALES, ...array_keys(Translation::REGIONAL_LOCALES)]).'.');

            return self::INVALID;
        }

        $dictionary = RegionalTranslations::derive($locale, (new TranslationSeeder)->dictionary($base) + FrontendTranslationSeeder::dictionary($base));

        $this->output->write(json_encode($dictionary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
