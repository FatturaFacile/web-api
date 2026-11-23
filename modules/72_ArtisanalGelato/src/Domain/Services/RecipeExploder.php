<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Domain\Services;

use InvalidArgumentException;

/**
 * Recursive explosion of a recipe into raw ingredients. A line is either an ingredient or a sub-recipe (a base, a paste, a
 * variegato, a crumble); a sub-recipe line contributes its grams in proportion to the composition of the sub-recipe, so 500 g of
 * a 1,000 g Base Bianca adds half of every ingredient of the base. The result is sorted by descending weight, as the
 * quantitative ingredient declaration requires, and carries the allergens, dietary flags and the defrosted notice.
 *
 * Graph shapes: recipes[id] = ['is_defrosted' => bool, 'lines' => list<['ingredient_id' => ?string, 'sub_recipe_id' => ?string,
 * 'grams' => float]>]; ingredients[id] = ['name' => array<locale,string>, 'allergens' => list<string>, 'is_vegan' => bool,
 * 'is_defrosted' => bool].
 */
final class RecipeExploder
{
    private const int MAX_DEPTH = 8;

    /**
     * @param  array<string, array{is_defrosted?: bool, lines: list<array{ingredient_id?: string|null, sub_recipe_id?: string|null, grams: float|int|string}>}>  $recipes
     * @param  array<string, array{name: array<string, string>, allergens?: list<string>, is_vegan?: bool, is_defrosted?: bool}>  $ingredients
     * @return array{ingredients: list<array{ingredient_id: string, grams: float, percent: float}>, allergens: list<string>, gluten_free: bool, lactose_free: bool, vegan: bool, defrosted: bool}
     */
    public function explode(string $recipeId, array $recipes, array $ingredients): array
    {
        $weights = [];
        $defrosted = false;
        $this->walk($recipeId, 1.0, $recipes, $ingredients, $weights, [], $defrosted);

        arsort($weights);
        $total = array_sum($weights);
        $rows = [];
        $allergens = [];
        $vegan = true;

        foreach ($weights as $ingredientId => $grams) {
            $ingredient = $ingredients[$ingredientId] ?? throw new InvalidArgumentException("Unknown ingredient {$ingredientId}.");
            $allergens = array_merge($allergens, $ingredient['allergens'] ?? []);
            $vegan = $vegan && ($ingredient['is_vegan'] ?? true);
            $defrosted = $defrosted || ($ingredient['is_defrosted'] ?? false);
            $rows[] = ['ingredient_id' => (string) $ingredientId, 'grams' => RecipeBalancingEngine::round($grams, 3), 'percent' => $total > 0 ? RecipeBalancingEngine::round($grams / $total * 100, 2) : 0.0];
        }

        $allergens = array_values(array_intersect(AllergenCatalog::codes(), array_unique($allergens)));

        return [
            'ingredients' => $rows,
            'allergens' => $allergens,
            'gluten_free' => ! in_array('gluten', $allergens, true),
            'lactose_free' => ! in_array('milk', $allergens, true),
            'vegan' => $vegan,
            'defrosted' => $defrosted,
        ];
    }

    /**
     * @param  array<string, mixed>  $recipes
     * @param  array<string, mixed>  $ingredients
     * @param  array<string, float>  $weights
     * @param  list<string>  $path
     */
    private function walk(string $recipeId, float $grams, array $recipes, array $ingredients, array &$weights, array $path, bool &$defrosted): void
    {
        if (in_array($recipeId, $path, true)) {
            throw new InvalidArgumentException('A recipe cannot contain itself (cycle through '.$recipeId.').');
        }

        if (count($path) >= self::MAX_DEPTH) {
            throw new InvalidArgumentException('Sub-recipes are nested too deeply.');
        }

        $recipe = $recipes[$recipeId] ?? throw new InvalidArgumentException("Unknown recipe {$recipeId}.");
        $defrosted = $defrosted || ($recipe['is_defrosted'] ?? false);
        $total = 0.0;

        foreach ($recipe['lines'] as $line) {
            $total += (float) $line['grams'];
        }

        if ($total <= 0) {
            return;
        }

        foreach ($recipe['lines'] as $line) {
            $share = $grams * (float) $line['grams'] / $total;

            if (! empty($line['sub_recipe_id'])) {
                $this->walk((string) $line['sub_recipe_id'], $share, $recipes, $ingredients, $weights, [...$path, $recipeId], $defrosted);
            } elseif (! empty($line['ingredient_id'])) {
                $ingredientId = (string) $line['ingredient_id'];
                $weights[$ingredientId] = ($weights[$ingredientId] ?? 0.0) + $share;
            }
        }
    }
}
