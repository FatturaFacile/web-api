<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Infrastructure\Console;

use Database\Seeders\FrontendTranslationSeeder;
use Database\Seeders\TranslationSeeder;
use Illuminate\Console\Command;
use ReflectionMethod;
use Throwable;

/**
 * CI gate: every static translation key the frontend source uses must exist in the seeded dictionaries of all four
 * languages (it, en, de, fr). Fails with a table of the missing keys.
 */
final class CheckMissingTranslationsCommand extends Command
{
    protected $signature = 'i18n:check-missing
        {--frontend= : Path of the web-frontend project (default: FRONTEND_PATH or ../web-frontend)}
        {--limit=200 : Rows of the missing keys table (0 = all)}';

    protected $description = 'Verify that every translation key used by the frontend exists in the Italian, English, German and French seeders';

    public function handle(FrontendKeyScanner $scanner): int
    {
        $root = $this->frontendRoot();

        if ($root === null) {
            $this->components->error('Frontend sources not found: pass --frontend=<path to web-frontend>.');

            return self::INVALID;
        }

        $used = $scanner->scan($root);
        $dictionaries = $this->dictionaries();
        $missing = [];

        foreach ($used as $key => $file) {
            $absent = array_keys(array_filter($dictionaries, static fn (array $dictionary): bool => ! array_key_exists($key, $dictionary)));

            if ($absent !== []) {
                $missing[] = [$key, implode(', ', $absent), $file];
            }
        }

        $this->components->info(sprintf('%d translation keys used by the frontend, %d missing.', count($used), count($missing)));

        if ($missing === []) {
            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $this->table(['Key', 'Missing in', 'First used in'], $limit > 0 ? array_slice($missing, 0, $limit) : $missing);
        $this->components->error('Add the keys to database/seeders/Translations/* for every locale (it, en, de, fr).');

        return self::FAILURE;
    }

    private function frontendRoot(): ?string
    {
        $candidates = array_filter([
            (string) $this->option('frontend'),
            (string) getenv('FRONTEND_PATH'),
            base_path('../web-frontend'),
        ]);

        foreach ($candidates as $candidate) {
            foreach ([$candidate.'/src', $candidate] as $path) {
                if (is_dir($path) && (is_dir($path.'/app') || str_ends_with(str_replace('\\', '/', $path), '/src'))) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * The seeded dictionary of each language: the frontend seeders and the backend ones share no keys, so a key is
     * known when either defines it.
     *
     * @return array<string, array<string, string>>
     */
    private function dictionaries(): array
    {
        $backend = new TranslationSeeder;
        $dictionaries = [];

        foreach (TranslationSeeder::LOCALES as $locale) {
            $dictionaries[$locale] = FrontendTranslationSeeder::dictionary($locale) + $backend->dictionary($locale) + $this->standaloneSeeders($locale);
        }

        return $dictionaries;
    }

    /**
     * The texts of the translation classes that a standalone seeder loads (CpqLocalizationSeeder, DataMigration, ...) rather
     * than the frontend seeder: every class of Database\Seeders\Translations with a static all(string $locale).
     *
     * @return array<string, string>
     */
    private function standaloneSeeders(string $locale): array
    {
        $texts = [];

        foreach (glob(database_path('seeders/Translations/*.php')) ?: [] as $file) {
            $class = 'Database\\Seeders\\Translations\\'.basename($file, '.php');

            if (! class_exists($class) || ! method_exists($class, 'all')) {
                continue;
            }

            $method = new ReflectionMethod($class, 'all');

            if (! $method->isStatic() || $method->getNumberOfRequiredParameters() !== 1 || $method->getParameters()[0]->getName() !== 'locale') {
                continue;
            }

            try {
                $texts += (array) $method->invoke(null, $locale);
            } catch (Throwable) {
                // A class that cannot serve this locale adds nothing
            }
        }

        return $texts;
    }
}
