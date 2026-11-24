<?php

declare(strict_types=1);

use Database\Seeders\FrontendTranslationSeeder;
use Database\Seeders\Translations\SalesPipelineFrontendTranslations;
use Modules\CRM\Support\LocalizedLabel;
use Modules\CRM\Support\SalesPipelineCatalog;
use Modules\CRM\Support\WeightedValue;

test('weighted value is deal value times probability with exact decimal arithmetic', function (string $amount, string $probability, string $expected): void {
    expect(WeightedValue::compute($amount, $probability))->toBe($expected);
})->with([
    'sixty percent' => ['10000', '60', '6000.00'],
    'fractional probability' => ['1999.99', '12.5', '250.00'],
    'rounds half up' => ['0.05', '10', '0.01'],
    'zero probability' => ['5000', '0', '0.00'],
    'full probability' => ['4321.10', '100', '4321.10'],
    'large value' => ['123456789.12', '33.33', '41148147.81'],
]);

test('probabilities outside 0..100 are clamped', function (): void {
    expect(WeightedValue::compute('1000', '150'))->toBe('1000.00')
        ->and(WeightedValue::compute('1000', '-5'))->toBe('0.00');
});

test('localized labels fall back through the regional variants to english and finally to the name', function (): void {
    $labels = ['it-CH' => 'Prezzo', 'de-CH' => 'Preis', 'fr-CH' => 'Prix', 'en' => 'Price', 'it-IT' => 'Costo'];

    expect(LocalizedLabel::resolve($labels, 'x', 'it'))->toBe('Prezzo')
        ->and(LocalizedLabel::resolve($labels, 'x', 'it-IT'))->toBe('Costo')
        ->and(LocalizedLabel::resolve($labels, 'x', 'de'))->toBe('Preis')
        ->and(LocalizedLabel::resolve($labels, 'x', 'fr_CH'))->toBe('Prix')
        ->and(LocalizedLabel::resolve($labels, 'x', 'en'))->toBe('Price')
        ->and(LocalizedLabel::resolve($labels, 'x', 'es'))->toBe('Price')
        ->and(LocalizedLabel::resolve(null, 'Fallback', 'de'))->toBe('Fallback')
        ->and(LocalizedLabel::resolve(['de-CH' => ''], 'Fallback', 'de'))->toBe('Fallback');
});

test('the seeded catalog carries every label in the five supported locales', function (): void {
    $rows = [...SalesPipelineCatalog::pipelines(), ...SalesPipelineCatalog::stages(), ...SalesPipelineCatalog::lossReasons()];

    foreach ($rows as $row) {
        foreach (LocalizedLabel::LOCALES as $locale) {
            expect(trim($row['translations'][$locale] ?? ''))->not->toBe('', "{$row['code']} lacks {$locale}");
        }
    }
});

test('the catalog follows the issue: terminal stages, one default pipeline and the mandatory loss reasons', function (): void {
    $stages = collect(SalesPipelineCatalog::stages());

    expect($stages->where('is_won', true))->toHaveCount(1)
        ->and($stages->where('is_lost', true))->toHaveCount(1)
        ->and($stages->firstWhere('code', 'closed_won')['probability'])->toBe(100.0)
        ->and($stages->firstWhere('code', 'closed_lost')['probability'])->toBe(0.0)
        ->and($stages->firstWhere('code', 'discovery')['translations']['it-CH'])->toBe('Qualificazione')
        ->and($stages->firstWhere('code', 'negotiation')['forecast_category'])->toBe('commit')
        ->and(collect(SalesPipelineCatalog::pipelines())->where('is_default', true))->toHaveCount(1)
        ->and(collect(SalesPipelineCatalog::lossReasons())->pluck('code')->all())->toContain('price', 'specific_competitor', 'missing_features', 'project_frozen');
});

test('every sales pipeline frontend key exists in the four languages', function (): void {
    $keys = array_keys(SalesPipelineFrontendTranslations::all('en'));

    expect($keys)->not->toBeEmpty();

    foreach (['it', 'de', 'fr', 'en'] as $locale) {
        $dictionary = FrontendTranslationSeeder::dictionary($locale);

        foreach ($keys as $key) {
            expect(trim($dictionary[$key] ?? ''))->not->toBe('', "{$locale} is missing {$key}");
        }
    }
});
