<?php

declare(strict_types=1);

namespace App\Console\Commands;

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
    protected $signature = 'translations:dump {locale : it, en, de or fr}';

    /**
     * @var string
     */
    protected $description = 'Print the seeded global dictionary of a language as JSON (no database needed)';

    public function handle(): int
    {
        $locale = (string) $this->argument('locale');

        if (! in_array($locale, TranslationSeeder::LOCALES, true)) {
            $this->components->error('Unsupported locale. Use one of: '.implode(', ', TranslationSeeder::LOCALES).'.');

            return self::INVALID;
        }

        $dictionary = (new TranslationSeeder)->dictionary($locale) + FrontendTranslationSeeder::dictionary($locale);

        $this->output->write(json_encode($dictionary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
