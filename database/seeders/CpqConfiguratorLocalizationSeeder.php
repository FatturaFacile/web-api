<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Translations\CpqConfiguratorPdfTranslations;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Global (tenant-less) translations of the CPQ configurator proposal PDF in it, en, de, fr. Idempotent: the rows of these
 * keys are replaced, and the rows of a tenant are never touched.
 */
class CpqConfiguratorLocalizationSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $now = now();

            foreach (TranslationSeeder::LOCALES as $locale) {
                $texts = CpqConfiguratorPdfTranslations::all($locale);

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
