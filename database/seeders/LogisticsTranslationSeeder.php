<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Translation;
use Database\Seeders\Translations\LogisticsFrontendTranslations;
use Database\Seeders\Translations\LogisticsPdfTranslations;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * API-driven i18n for the Logistics / DDT / Transfers / Consignment screens (IT, EN, DE, FR).
 * Idempotent: upserts on (tenant_id, locale, key).
 */
class LogisticsTranslationSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $locales = ['it', 'en', 'de', 'fr'];

        DB::transaction(function () use ($locales, $now): void {
            foreach ($locales as $index => $locale) {
                $payload = [];
                foreach (self::translations() as $key => $values) {
                    $payload[] = [
                        'tenant_id' => null,
                        'locale' => $locale,
                        'key' => $key,
                        'value' => $values[$index],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                foreach (array_chunk($payload, 100) as $chunk) {
                    Translation::upsert($chunk, ['tenant_id', 'locale', 'key'], ['value', 'updated_at']);
                }
            }
        });

        foreach ($locales as $locale) {
            Cache::forget("translations_global_{$locale}");
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}> key => [it, en, de, fr]
     */
    public static function translations(): array
    {
        return LogisticsFrontendTranslations::all() + LogisticsPdfTranslations::all();
    }
}
