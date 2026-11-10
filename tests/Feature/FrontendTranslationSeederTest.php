<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\Translation;
use Database\Seeders\FrontendTranslationSeeder;
use Database\Seeders\Translations\EnglishFrontendTranslations;
use Database\Seeders\Translations\FrenchFrontendTranslations;
use Database\Seeders\Translations\GermanFrontendTranslations;
use Database\Seeders\Translations\ItalianFrontendTranslations;
use Database\Seeders\TranslationSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

/**
 * @return array<string, array<string, string>>
 */
function frontendDictionaries(): array
{
    return [
        'it' => ItalianFrontendTranslations::all(),
        'en' => EnglishFrontendTranslations::all(),
        'de' => GermanFrontendTranslations::all(),
        'fr' => FrenchFrontendTranslations::all(),
    ];
}

/**
 * @return list<string>
 */
function placeholdersOf(string $text): array
{
    preg_match_all('/\{\w+}|:\w+/', $text, $matches);
    $found = $matches[0];
    sort($found);

    return $found;
}

test('the four languages hold the same keys', function (): void {
    $dictionaries = frontendDictionaries();
    $keys = array_keys($dictionaries['en']);

    foreach (['it', 'de', 'fr'] as $locale) {
        expect(array_keys($dictionaries[$locale]))->toEqualCanonicalizing($keys);
    }

    expect($keys)->toContain('exportsPage.csvTitle');
});

test('no value is empty and the placeholders match across languages', function (): void {
    $dictionaries = frontendDictionaries();

    foreach ($dictionaries['en'] as $key => $english) {
        foreach ($dictionaries as $locale => $dictionary) {
            expect(trim($dictionary[$key]))->not->toBe('', "{$locale} {$key} is empty")
                ->and(placeholdersOf($dictionary[$key]))->toBe(placeholdersOf($english), "{$locale} {$key} changes the placeholders");
        }
    }
});

test('the keys never overlap the ones of the base TranslationSeeder', function (): void {
    Translation::query()->whereNull('tenant_id')->delete();
    $this->seed(TranslationSeeder::class);

    $base = Translation::query()->whereNull('tenant_id')->where('locale', 'en')->pluck('key')->all();

    expect(array_intersect($base, array_keys(EnglishFrontendTranslations::all())))->toBe([]);
});

test('seeding twice leaves one global row per key and language', function (): void {
    $this->seed(FrontendTranslationSeeder::class);
    $this->seed(FrontendTranslationSeeder::class);

    foreach (frontendDictionaries() as $locale => $dictionary) {
        $rows = Translation::query()->whereNull('tenant_id')->where('locale', $locale)->whereIn('key', array_keys($dictionary))->count();

        expect($rows)->toBe(count($dictionary));
    }
});

test('the translations endpoint returns the seeded values', function (): void {
    $this->seed(FrontendTranslationSeeder::class);

    foreach (frontendDictionaries() as $locale => $dictionary) {
        $translations = $this->getJson("/api/v1/translations/{$locale}")->assertOk()->json('translations');

        expect($translations['exportsPage.csvTitle'])->toBe($dictionary['exportsPage.csvTitle'])
            ->and($translations['sales.messages.quote_created'])->toBe($dictionary['sales.messages.quote_created']);
    }
});

test('the rows of a tenant are never touched', function (): void {
    $tenant = Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Seeder Tenant',
        'legal_name' => 'Seeder Tenant S.r.l.',
        'vat_number' => 'IT12345678901',
        'status' => 'active',
    ]);
    $custom = Translation::create([
        'tenant_id' => $tenant->id,
        'locale' => 'en',
        'key' => 'exportsPage.csvTitle',
        'value' => 'Custom title',
    ]);

    $this->seed(FrontendTranslationSeeder::class);

    expect($custom->fresh()->value)->toBe('Custom title');
});
