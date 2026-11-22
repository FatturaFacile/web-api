<?php

declare(strict_types=1);

use Modules\ArtisanalGelato\Domain\Services\AllergenCatalog;
use Modules\ArtisanalGelato\Domain\Services\GanacheWaterActivityCalculator;
use Modules\ArtisanalGelato\Domain\Services\HaccpThermalCurveEvaluator;
use Modules\ArtisanalGelato\Domain\Services\RecipeExploder;
use Modules\ArtisanalGelato\Domain\Services\TemperingCurveCatalog;

it('estimates a high water activity for a fresh cream ganache and a stable one for a sugar-rich filling', function (): void {
    $calculator = new GanacheWaterActivityCalculator;

    $ganache = $calculator->calculate([
        ['grams' => 300, 'water_percent' => 1, 'sugar_percent' => 44, 'solute_class' => 'sucrose'],
        ['grams' => 300, 'water_percent' => 59.4, 'sugar_percent' => 0, 'solute_class' => 'none'],
    ]);
    $filling = $calculator->calculate([
        ['grams' => 500, 'water_percent' => 0, 'sugar_percent' => 100, 'solute_class' => 'sucrose'],
        ['grams' => 100, 'water_percent' => 100, 'sugar_percent' => 0, 'solute_class' => 'none'],
    ]);

    expect($ganache['water_activity'])->toBeGreaterThan(0.90)
        ->and($ganache['shelf_life_days'])->toBeLessThanOrEqual(7)
        ->and($filling['water_activity'])->toBeLessThan(0.70)
        ->and($filling['shelf_life_days'])->toBe(365)
        ->and($filling['risk_band'])->toBe('stable');
});

it('maps water activity to shelf life bands', function (): void {
    $calculator = new GanacheWaterActivityCalculator;

    expect($calculator->shelfLife(0.75))->toBe([180, 'low'])
        ->and($calculator->shelfLife(0.82))->toBe([60, 'moderate'])
        ->and($calculator->shelfLife(0.97))->toBe([3, 'critical']);
});

it('validates tempering curves of dark, milk and white chocolate', function (): void {
    $catalog = new TemperingCurveCatalog;

    expect($catalog->validate('dark', ['melt' => 47, 'cool' => 27.5, 'work' => 31.5])['valid'])->toBeTrue()
        ->and($catalog->validate('dark', ['melt' => 47, 'cool' => 27.5, 'work' => 35])['stages']['work']['ok'])->toBeFalse()
        ->and($catalog->validate('white', ['melt' => 42, 'cool' => 25.5, 'work' => 28.5])['valid'])->toBeTrue()
        ->and(fn () => $catalog->curve('ruby'))->toThrow(InvalidArgumentException::class);
});

it('passes a pasteurization curve that reaches 85 C and cools to 4 C within two hours', function (): void {
    $result = (new HaccpThermalCurveEvaluator)->evaluate([
        ['at' => '2027-03-01 08:00:00', 'temperature_celsius' => 20, 'phase' => 'heat'],
        ['at' => '2027-03-01 08:30:00', 'temperature_celsius' => 86, 'phase' => 'heat'],
        ['at' => '2027-03-01 08:35:00', 'temperature_celsius' => 86, 'phase' => 'heat'],
        ['at' => '2027-03-01 09:30:00', 'temperature_celsius' => 4, 'phase' => 'cool'],
        ['at' => '2027-03-02 08:00:00', 'temperature_celsius' => -19, 'phase' => 'harden'],
    ]);

    expect($result['passed'])->toBeTrue()
        ->and($result['cooling_minutes'])->toBe(55)
        ->and($result['peak_celsius'])->toBe(86.0);
});

it('fails curves that miss pasteurization, cool too slowly or do not harden', function (): void {
    $evaluator = new HaccpThermalCurveEvaluator;

    expect($evaluator->evaluate([['at' => '2027-03-01 08:00:00', 'temperature_celsius' => 80], ['at' => '2027-03-01 09:00:00', 'temperature_celsius' => 4]])['findings'])->toBe(['HACCP_PASTEURIZATION_NOT_REACHED'])
        ->and($evaluator->evaluate([['at' => '2027-03-01 08:00:00', 'temperature_celsius' => 86], ['at' => '2027-03-01 11:00:00', 'temperature_celsius' => 4]])['findings'])->toBe(['HACCP_COOLING_TOO_SLOW'])
        ->and($evaluator->evaluate([['at' => '2027-03-01 08:00:00', 'temperature_celsius' => 86], ['at' => '2027-03-01 08:40:00', 'temperature_celsius' => 4], ['at' => '2027-03-01 18:00:00', 'temperature_celsius' => -10, 'phase' => 'harden']])['findings'])->toBe(['HACCP_HARDENING_NOT_REACHED'])
        ->and($evaluator->evaluate([])['findings'])->toBe(['HACCP_NO_READINGS']);
});

/**
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function gelatoGraph(): array
{
    $recipes = [
        'base' => ['lines' => [['ingredient_id' => 'milk', 'grams' => 600], ['ingredient_id' => 'sugar', 'grams' => 400]]],
        'pistachio' => ['lines' => [['sub_recipe_id' => 'base', 'grams' => 500], ['ingredient_id' => 'pistachio', 'grams' => 500]]],
        'loop' => ['lines' => [['sub_recipe_id' => 'loop', 'grams' => 100]]],
    ];
    $ingredients = [
        'milk' => ['name' => ['en' => 'Milk'], 'allergens' => ['milk'], 'is_vegan' => false],
        'sugar' => ['name' => ['en' => 'Sugar'], 'allergens' => [], 'is_vegan' => true],
        'pistachio' => ['name' => ['en' => 'Pistachio'], 'allergens' => ['nuts'], 'is_vegan' => true, 'is_defrosted' => false],
    ];

    return [$recipes, $ingredients];
}

it('explodes sub-recipes recursively, orders by descending weight and aggregates allergens', function (): void {
    [$recipes, $ingredients] = gelatoGraph();
    $result = (new RecipeExploder)->explode('pistachio', $recipes, $ingredients);

    expect(array_column($result['ingredients'], 'ingredient_id'))->toBe(['pistachio', 'milk', 'sugar'])
        ->and(array_column($result['ingredients'], 'percent'))->toBe([50.0, 30.0, 20.0])
        ->and($result['allergens'])->toBe(['milk', 'nuts'])
        ->and($result['lactose_free'])->toBeFalse()
        ->and($result['gluten_free'])->toBeTrue()
        ->and($result['vegan'])->toBeFalse()
        ->and($result['defrosted'])->toBeFalse();
});

it('propagates the defrosted flag of an ingredient and rejects cycles', function (): void {
    [$recipes, $ingredients] = gelatoGraph();
    $ingredients['sugar']['is_defrosted'] = true;

    expect((new RecipeExploder)->explode('base', $recipes, $ingredients)['defrosted'])->toBeTrue()
        ->and(fn () => (new RecipeExploder)->explode('loop', $recipes, $ingredients))->toThrow(InvalidArgumentException::class);
});

it('lists the 14 allergens in the four languages with the defrosted notice', function (): void {
    expect(AllergenCatalog::codes())->toHaveCount(14);

    foreach (['it', 'de', 'fr', 'en'] as $locale) {
        expect(AllergenCatalog::names($locale))->toHaveCount(14);
    }

    expect(AllergenCatalog::names('de')['milk'])->toBe('Milch und Laktose')
        ->and(AllergenCatalog::defrostedNotice('it'))->toBe('Decongelato')
        ->and(AllergenCatalog::defrostedNotice('fr'))->toBe('Décongelé');
});
