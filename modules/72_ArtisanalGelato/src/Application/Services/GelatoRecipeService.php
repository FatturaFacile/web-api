<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Application\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\ArtisanalGelato\Domain\Enums\RecipeCategory;
use Modules\ArtisanalGelato\Domain\Services\RecipeBalancingEngine;
use Modules\ArtisanalGelato\Domain\Services\RecipeExploder;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoBalancedRecipe;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoRecipeLine;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoTechnicalIngredient;

/**
 * Loads recipe graphs, runs the balancing engine on them and persists recipes with their calculated vector. A recipe line is an
 * ingredient or a sub-recipe (a base, a paste, a variegato); sub-recipes are exploded to raw ingredients before the indexes are
 * computed, so a flavor built on "Base Bianca" is balanced on its true composition.
 */
final class GelatoRecipeService
{
    private const string DRAFT = '__draft__';

    public function __construct(private readonly RecipeBalancingEngine $engine, private readonly RecipeExploder $exploder) {}

    /**
     * Metrics of a draft recipe (the lines are not stored).
     *
     * @param  list<array{ingredient_id?: string|null, sub_recipe_id?: string|null, grams: float|int|string}>  $lines
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function preview(array $lines, RecipeCategory $category, array $options = [], ?string $recipeId = null): array
    {
        return $this->engine->analyze($this->engineLines($lines, $recipeId), $category, $options);
    }

    /**
     * Raw ingredient lines (with their technical attributes and grams) of a list of recipe lines.
     *
     * @param  list<array{ingredient_id?: string|null, sub_recipe_id?: string|null, grams: float|int|string}>  $lines
     * @return list<array<string, mixed>>
     */
    public function engineLines(array $lines, ?string $selfId = null): array
    {
        [$recipes, $ingredients] = $this->graph($lines, $selfId);
        $total = array_sum(array_map(static fn (array $l): float => (float) $l['grams'], $lines));
        $exploded = $this->exploder->explode(self::DRAFT, $recipes, $ingredients);
        $engineLines = [];

        foreach ($exploded['ingredients'] as $row) {
            $ingredient = $ingredients[$row['ingredient_id']];
            $engineLines[] = ['grams' => $row['grams'] * $total] + $ingredient['technical'];
        }

        return $engineLines;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    public function graph(array $lines, ?string $selfId = null): array
    {
        $recipes = [self::DRAFT => ['is_defrosted' => false, 'lines' => $lines]];
        $pending = array_values(array_filter(array_column($lines, 'sub_recipe_id')));

        if ($selfId !== null && in_array($selfId, $pending, true)) {
            throw new InvalidArgumentException('A recipe cannot contain itself.');
        }

        while ($pending !== []) {
            $batch = array_values(array_diff(array_unique($pending), array_keys($recipes)));
            $pending = [];

            if ($batch === []) {
                break;
            }

            $found = GelatoBalancedRecipe::query()->whereIn('id', $batch)->get()->keyBy('id');

            foreach ($batch as $id) {
                if (! $found->has($id)) {
                    throw new InvalidArgumentException("Unknown sub-recipe {$id}.");
                }
            }

            $subLines = GelatoRecipeLine::query()->whereIn('recipe_id', $batch)->orderBy('position')->get()->groupBy('recipe_id');

            foreach ($batch as $id) {
                $recipeLines = $subLines->get($id, collect())->map(static fn (GelatoRecipeLine $l): array => ['ingredient_id' => $l->ingredient_id, 'sub_recipe_id' => $l->sub_recipe_id, 'grams' => (float) $l->grams])->all();
                $recipes[$id] = ['is_defrosted' => (bool) $found[$id]->is_defrosted, 'lines' => $recipeLines];
                $pending = [...$pending, ...array_values(array_filter(array_column($recipeLines, 'sub_recipe_id')))];
            }
        }

        $ingredientIds = [];

        foreach ($recipes as $recipe) {
            $ingredientIds = [...$ingredientIds, ...array_values(array_filter(array_column($recipe['lines'], 'ingredient_id')))];
        }

        $ingredients = [];

        foreach (GelatoTechnicalIngredient::query()->whereIn('id', array_unique($ingredientIds))->get() as $ingredient) {
            $ingredients[$ingredient->id] = $this->ingredientNode($ingredient);
        }

        foreach (array_unique($ingredientIds) as $id) {
            if (! isset($ingredients[$id])) {
                throw new InvalidArgumentException("Unknown ingredient {$id}.");
            }
        }

        return [$recipes, $ingredients];
    }

    /**
     * Creates or replaces a recipe and recomputes its vector. Returns the stored recipe.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(?string $id, array $data): GelatoBalancedRecipe
    {
        $category = RecipeCategory::from((string) $data['category']);
        $lines = array_values($data['lines']);
        $metrics = $this->preview($lines, $category, [
            'overrun_percent' => $data['overrun_percent'] ?? 0,
            'serving_portion_grams' => $data['serving_portion_grams'] ?? 80,
            'tub_weight_grams' => $data['tub_weight_grams'] ?? 2500,
        ], $id);
        [$recipes, $ingredients] = $this->graph($lines, $id);
        $exploded = $this->exploder->explode(self::DRAFT, $recipes, $ingredients);

        return DB::transaction(function () use ($id, $data, $lines, $metrics, $exploded): GelatoBalancedRecipe {
            $recipe = $id === null ? new GelatoBalancedRecipe : GelatoBalancedRecipe::query()->findOrFail($id);
            $recipe->fill([
                'code' => $data['code'],
                'name' => $data['name'],
                'category' => $data['category'],
                'kind' => $data['kind'],
                'batch_weight_grams' => $data['batch_weight_grams'] ?? array_sum(array_column($lines, 'grams')),
                'serving_portion_grams' => $data['serving_portion_grams'] ?? 80,
                'tub_weight_grams' => $data['tub_weight_grams'] ?? 2500,
                'is_defrosted' => $exploded['defrosted'] || (bool) ($data['is_defrosted'] ?? false),
                'calculated_pod_percent' => $metrics['pod'],
                'calculated_pac_index' => $metrics['pac'],
                'milk_fat_percent' => $metrics['milk_fat_percent'],
                'other_fat_percent' => $metrics['other_fat_percent'],
                'slng_percent' => $metrics['slng_percent'],
                'slng_max_safe_threshold' => $metrics['slng_max_safe_threshold'],
                'total_solids_percent' => $metrics['total_solids_percent'],
                'ideal_serving_temp_celsius' => $metrics['ideal_serving_temp_celsius'],
                'overrun_percent' => $data['overrun_percent'] ?? 0,
                'food_cost_per_kg' => $metrics['cost']['cost_per_kg'],
                'metrics' => $metrics,
                'allergens' => $exploded['allergens'],
                'dietary' => ['gluten_free' => $exploded['gluten_free'], 'lactose_free' => $exploded['lactose_free'], 'vegan' => $exploded['vegan']],
            ])->save();

            GelatoRecipeLine::query()->where('recipe_id', $recipe->id)->forceDelete();

            foreach ($lines as $position => $line) {
                GelatoRecipeLine::query()->create([
                    'recipe_id' => $recipe->id,
                    'ingredient_id' => $line['ingredient_id'] ?? null,
                    'sub_recipe_id' => $line['sub_recipe_id'] ?? null,
                    'grams' => $line['grams'],
                    'position' => $position,
                ]);
            }

            return $recipe;
        });
    }

    public function cloneRecipe(string $id, string $code): GelatoBalancedRecipe
    {
        $source = GelatoBalancedRecipe::query()->findOrFail($id);
        $lines = GelatoRecipeLine::query()->where('recipe_id', $id)->orderBy('position')->get()
            ->map(static fn (GelatoRecipeLine $l): array => ['ingredient_id' => $l->ingredient_id, 'sub_recipe_id' => $l->sub_recipe_id, 'grams' => (float) $l->grams])->all();

        return $this->save(null, [
            'code' => $code,
            'name' => array_map(static fn (string $name): string => $name.' (copy)', (array) $source->name),
            'category' => $source->category,
            'kind' => $source->kind,
            'batch_weight_grams' => (float) $source->batch_weight_grams,
            'serving_portion_grams' => (float) $source->serving_portion_grams,
            'tub_weight_grams' => (float) $source->tub_weight_grams,
            'is_defrosted' => $source->is_defrosted,
            'overrun_percent' => (float) $source->overrun_percent,
            'lines' => $lines,
        ]);
    }

    /**
     * The grams of every line for another batch weight (the indexes do not change: they are per 1,000 g).
     *
     * @return array{batch_weight_grams: float, factor: float, lines: list<array<string, mixed>>, cost: float}
     */
    public function scale(string $id, float $batchWeightGrams): array
    {
        if ($batchWeightGrams <= 0) {
            throw new InvalidArgumentException('The batch weight must be positive.');
        }

        $recipe = GelatoBalancedRecipe::query()->findOrFail($id);
        $lines = GelatoRecipeLine::query()->where('recipe_id', $id)->orderBy('position')->get();
        $total = (float) $lines->sum('grams');
        $factor = $batchWeightGrams / $total;

        return [
            'batch_weight_grams' => $batchWeightGrams,
            'factor' => RecipeBalancingEngine::round($factor, 6),
            'lines' => $lines->map(static fn (GelatoRecipeLine $l): array => [
                'ingredient_id' => $l->ingredient_id,
                'sub_recipe_id' => $l->sub_recipe_id,
                'grams' => RecipeBalancingEngine::round((float) $l->grams * $factor, 2),
            ])->all(),
            'cost' => RecipeBalancingEngine::round((float) $recipe->food_cost_per_kg * $batchWeightGrams / 1000, 2),
        ];
    }

    /**
     * @return array{name: array<string, string>, allergens: list<string>, is_vegan: bool, is_defrosted: bool, technical: array<string, mixed>}
     */
    private function ingredientNode(GelatoTechnicalIngredient $ingredient): array
    {
        return [
            'name' => (array) $ingredient->name,
            'allergens' => (array) $ingredient->allergens,
            'is_vegan' => (bool) $ingredient->is_vegan,
            'is_defrosted' => (bool) $ingredient->is_defrosted,
            'technical' => [
                'id' => (string) $ingredient->id,
                'pod' => (float) $ingredient->pod,
                'pac' => (float) $ingredient->pac,
                'milk_fat_percent' => (float) $ingredient->milk_fat_percent,
                'other_fat_percent' => (float) $ingredient->other_fat_percent,
                'slng_percent' => (float) $ingredient->slng_percent,
                'total_solids_percent' => (float) $ingredient->total_solids_percent,
                'water_percent' => (float) $ingredient->water_percent,
                'fiber_percent' => (float) $ingredient->fiber_percent,
                'cost_per_kg' => (float) $ingredient->cost_per_kg,
                'sugar_percent' => (float) $ingredient->sugar_percent,
                'solute_class' => (string) $ingredient->solute_class,
            ],
        ];
    }

    public static function lotCode(string $prefix): string
    {
        return $prefix.'-'.now()->format('ymd').'-'.Str::upper(Str::random(4));
    }
}
