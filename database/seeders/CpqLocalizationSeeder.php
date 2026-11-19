<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Translation;
use Database\Seeders\Translations\CpqTranslations;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * UI labels and quote PDF clauses of the CPQ lite quoting in it, en, de and fr, as global translations. Idempotent:
 * every run leaves one row per locale and key, and never touches the rows of a tenant.
 */
class CpqLocalizationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (TranslationSeeder::LOCALES as $locale) {
            foreach (CpqTranslations::all($locale) as $key => $value) {
                $row = Translation::withoutGlobalScopes()->whereNull('tenant_id')->where('locale', $locale)->where('key', $key)->first() ?? new Translation(['tenant_id' => null, 'locale' => $locale, 'key' => $key]);
                $row->forceFill(['tenant_id' => null, 'value' => $value])->save();
            }

            Cache::forget("translations_global_{$locale}");
        }
    }
}
