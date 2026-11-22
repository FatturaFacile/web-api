<?php

declare(strict_types=1);

use Modules\ArtisanalGelato\Domain\Enums\RecipeCategory;
use Modules\ArtisanalGelato\Domain\Services\AutoBalanceSolver;
use Modules\ArtisanalGelato\Domain\Services\RecipeBalancingEngine;

/**
 * @return list<array<string, mixed>>
 */
function gelatoCreamLines(): array
{
    return [
        ['grams' => 160, 'pod' => 100, 'pac' => 100, 'total_solids_percent' => 100, 'water_percent' => 0, 'cost_per_kg' => 1.1],
        ['grams' => 600, 'pod' => 0.8, 'milk_fat_percent' => 3.6, 'slng_percent' => 8.6, 'total_solids_percent' => 12.2, 'water_percent' => 87.8, 'cost_per_kg' => 1.3],
        ['grams' => 100, 'pod' => 0.5, 'milk_fat_percent' => 35, 'slng_percent' => 5.6, 'total_solids_percent' => 40.6, 'water_percent' => 59.4, 'cost_per_kg' => 4.2],
        ['grams' => 140, 'total_solids_percent' => 0, 'water_percent' => 100, 'cost_per_kg' => 0],
    ];
}

it('computes POD, PAC, fats, SLNG, solids and the lactose ceiling per 1000 g', function (): void {
    $metrics = (new RecipeBalancingEngine)->analyze(gelatoCreamLines(), RecipeCategory::DairyCream);

    expect($metrics['pod'])->toBe(165.3)
        ->and($metrics['pac_milk_salts'])->toBe(30.89)
        ->and($metrics['pac'])->toBe(190.89)
        ->and($metrics['milk_fat_percent'])->toBe(5.66)
        ->and($metrics['slng_percent'])->toBe(5.72)
        ->and($metrics['total_solids_percent'])->toBe(27.38)
        ->and($metrics['water_percent'])->toBe(72.62)
        ->and($metrics['slng_max_safe_threshold'])->toBe(10.52)
        ->and($metrics['lactose_risk']['status'])->toBe('OK');
});

it('does not depend on the batch weight', function (): void {
    $engine = new RecipeBalancingEngine;
    $doubled = array_map(static fn (array $line): array => ['grams' => $line['grams'] * 3.5] + $line, gelatoCreamLines());

    expect($engine->analyze($doubled, RecipeCategory::DairyCream)['pac'])->toBe($engine->analyze(gelatoCreamLines(), RecipeCategory::DairyCream)['pac']);
});

it('flags lactose crystallization when SLNG exceeds (100 - total solids) / 6.9', function (): void {
    $metrics = (new RecipeBalancingEngine)->analyze([
        ['grams' => 300, 'slng_percent' => 95, 'total_solids_percent' => 96, 'milk_fat_percent' => 0.8],
        ['grams' => 700, 'total_solids_percent' => 0, 'water_percent' => 100],
    ], RecipeCategory::DairyCream);

    expect($metrics['lactose_risk']['status'])->toBe('EXCEEDED')
        ->and($metrics['evaluation']['indicators']['slng']['status'])->toBe('OUT_OF_RANGE');
});

it('computes overrun and cost per kg, litre, tub and portion', function (): void {
    $engine = new RecipeBalancingEngine;
    $metrics = $engine->analyze(gelatoCreamLines(), RecipeCategory::DairyCream, ['overrun_percent' => 30, 'serving_portion_grams' => 100, 'tub_weight_grams' => 2000]);

    expect($engine->overrun(1.5, 1.0))->toBe(50.0)
        ->and($metrics['cost']['cost_per_kg'])->toBe(1.376)
        ->and($metrics['cost']['batch_cost'])->toBe(1.38)
        ->and($metrics['cost']['cost_per_tub'])->toBe(2.752)
        ->and($metrics['cost']['cost_per_portion'])->toBe(0.1376)
        ->and($metrics['cost']['gelato_litres'])->toBeGreaterThan($metrics['cost']['mix_litres'])
        ->and($metrics['cost']['cost_per_litre_gelato'])->toBeLessThan($metrics['cost']['cost_per_litre_mix']);
});

it('builds a freezing curve whose ice grows as the temperature falls', function (): void {
    $curve = (new RecipeBalancingEngine)->analyze(gelatoCreamLines(), RecipeCategory::DairyCream)['freezing_curve'];

    expect($curve)->toHaveCount(9)
        ->and($curve[8]['frozen_water_percent'])->toBeGreaterThan($curve[0]['frozen_water_percent'])
        ->and($curve[0]['temperature_celsius'])->toBe(-10);
});

it('rejects empty and negative recipes', function (): void {
    $engine = new RecipeBalancingEngine;

    expect(fn () => $engine->analyze([], RecipeCategory::DairyCream))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $engine->analyze([['grams' => -1]], RecipeCategory::DairyCream))->toThrow(InvalidArgumentException::class);
});

it('solves the auto balance to the target weight, POD and PAC', function (): void {
    $solver = new AutoBalanceSolver(new RecipeBalancingEngine);
    $result = $solver->solve([
        ['id' => 'sucrose', 'pod' => 100, 'pac' => 100, 'total_solids_percent' => 100, 'water_percent' => 0],
        ['id' => 'dextrose', 'pod' => 70, 'pac' => 190, 'total_solids_percent' => 100, 'water_percent' => 0],
        ['id' => 'water', 'total_solids_percent' => 0, 'water_percent' => 100],
    ], ['weight' => 1000, 'pod' => 160, 'pac' => 240], RecipeCategory::FruitSorbet);

    expect($result['status'])->toBe('EXACT')
        ->and(array_sum($result['weights']))->toEqualWithDelta(1000, 0.5)
        ->and($result['weights']['dextrose'])->toEqualWithDelta(66.67, 0.5)
        ->and($result['weights']['sucrose'])->toEqualWithDelta(113.33, 0.5)
        ->and($result['achieved']['pod'])->toEqualWithDelta(160, 1.0);
});

it('reports the closest feasible solution when targets cannot be met', function (): void {
    $solver = new AutoBalanceSolver(new RecipeBalancingEngine);
    $result = $solver->solve([['id' => 'water', 'total_solids_percent' => 0, 'water_percent' => 100]], ['weight' => 1000, 'pod' => 160], RecipeCategory::FruitSorbet);

    expect($result['status'])->toBe('CLOSEST_FEASIBLE')
        ->and($result['residuals']['pod']['met'])->toBeFalse()
        ->and($result['weights']['water'])->toBeGreaterThanOrEqual(0.0);
});

it('respects the maximum grams bound of an ingredient', function (): void {
    $solver = new AutoBalanceSolver(new RecipeBalancingEngine);
    $result = $solver->solve([
        ['id' => 'sucrose', 'pod' => 100, 'pac' => 100, 'total_solids_percent' => 100, 'water_percent' => 0, 'max_grams' => 50],
        ['id' => 'water', 'total_solids_percent' => 0, 'water_percent' => 100],
    ], ['weight' => 1000, 'pod' => 160], RecipeCategory::FruitSorbet);

    expect($result['weights']['sucrose'])->toBeLessThanOrEqual(50.0)
        ->and($result['status'])->toBe('CLOSEST_FEASIBLE');
});
