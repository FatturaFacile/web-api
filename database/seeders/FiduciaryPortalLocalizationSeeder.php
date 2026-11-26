<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Translations\FiduciaryPortalFrontendTranslations;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Seeds Mod 032 External Accountant & Fiduciary Portal UI strings across all 5 locales:
 * it-CH, de-CH, fr-CH, en, it-IT (plus base locales it, de, fr).
 */
final class FiduciaryPortalLocalizationSeeder extends Seeder
{
    public const array FIVE_LOCALES = ['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT'];

    public function run(): void
    {
        $localesToSeed = array_values(array_unique([...self::FIVE_LOCALES, 'it', 'de', 'fr']));
        $now = now();

        DB::transaction(function () use ($localesToSeed, $now): void {
            foreach ($localesToSeed as $locale) {
                $translations = FiduciaryPortalFrontendTranslations::all($locale);
                $keys = array_keys($translations);

                foreach (array_chunk($keys, 500) as $chunkKeys) {
                    DB::table('translations')
                        ->whereNull('tenant_id')
                        ->where('locale', $locale)
                        ->whereIn('key', $chunkKeys)
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

                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('translations')->insert($chunk);
                }
            }
        });

        foreach ($localesToSeed as $locale) {
            Cache::forget("translations_global_{$locale}");
        }
    }
}
