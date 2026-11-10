<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Translations\EnglishFrontendTranslations;
use Database\Seeders\Translations\FrenchFrontendTranslations;
use Database\Seeders\Translations\GermanFrontendTranslations;
use Database\Seeders\Translations\ItalianFrontendTranslations;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Global UI strings (it, en, de, fr) of the Angular frontend that TranslationSeeder does not hold.
 *
 * The API serves its own dictionary and only falls back to the static files of the frontend when it is empty, so a key
 * that is missing here shows up as the raw key in the interface. The keys of this seeder never overlap the ones of
 * TranslationSeeder.
 *
 * The unique index on (tenant_id, locale, key) does not catch duplicates while tenant_id is NULL, so the global rows of
 * these keys are replaced instead of upserted: running the seeder again leaves one row per key. Rows of a tenant are
 * never touched.
 */
class FrontendTranslationSeeder extends Seeder
{
    /**
     * The strings of one language that TranslationSeeder does not hold.
     *
     * @return array<string, string>
     */
    public static function dictionary(string $locale): array
    {
        return match ($locale) {
            'it' => ItalianFrontendTranslations::all(),
            'en' => EnglishFrontendTranslations::all(),
            'de' => GermanFrontendTranslations::all(),
            'fr' => FrenchFrontendTranslations::all(),
            default => throw new InvalidArgumentException("Unsupported locale [{$locale}]."),
        };
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $locales = [];

        foreach (TranslationSeeder::LOCALES as $locale) {
            $locales[$locale] = self::dictionary($locale);
        }

        DB::transaction(function () use ($locales): void {
            $now = now();

            foreach ($locales as $locale => $translations) {
                foreach (array_chunk(array_keys($translations), 1000) as $keys) {
                    DB::table('translations')
                        ->whereNull('tenant_id')
                        ->where('locale', $locale)
                        ->whereIn('key', $keys)
                        ->delete();
                }

                $rows = [];

                foreach ($translations as $key => $value) {
                    $rows[] = [
                        'tenant_id' => null,
                        'locale' => $locale,
                        'key' => $key,
                        'value' => $value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('translations')->insert($chunk);
                }
            }
        });

        foreach (array_keys($locales) as $locale) {
            Cache::forget("translations_global_{$locale}");
        }
    }
}
