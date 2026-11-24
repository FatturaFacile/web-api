<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Translations\CpqTranslations;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * UI labels and quote PDF clauses of the CPQ lite quoting in it, en, de and fr, as global translations. Idempotent:
 * the unique index on (tenant_id, locale, key) does not catch duplicates while tenant_id is NULL, so the global rows of
 * these keys are replaced, and every run leaves one row per locale and key. The rows of a tenant are never touched.
 */
class CpqLocalizationSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $now = now();

            foreach (TranslationSeeder::LOCALES as $locale) {
                $texts = CpqTranslations::all($locale);

                DB::table('translations')->whereNull('tenant_id')->where('locale', $locale)->whereIn('key', array_keys($texts))->delete();
                DB::table('translations')->insert(array_map(
                    static fn (string $key, string $value): array => ['tenant_id' => null, 'locale' => $locale, 'key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now],
                    array_keys($texts),
                    array_values($texts)
                ));
            }
        });

        foreach (TranslationSeeder::LOCALES as $locale) {
            Cache::forget("translations_global_{$locale}");
        }
    }
}
