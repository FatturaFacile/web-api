<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Application\Commands;

use Modules\ArtisanalGelato\Domain\Enums\RecipeCategory;

/**
 * Real-time calculation of a recipe (POD, PAC, fats, SLNG, solids, overrun, serving temperature, freezing curve, cost) or, when
 * targets are given, the auto balance of the chosen ingredients towards them.
 *
 * @phpstan-type Line array{ingredient_id?: string|null, sub_recipe_id?: string|null, grams: float|int|string}
 * @phpstan-type PoolItem array{ingredient_id: string, min_grams?: float|int|null, max_grams?: float|int|null}
 */
final readonly class BalanceGelatoRecipeParametersCommand
{
    /**
     * @param  list<Line>  $lines  The recipe as it is (preview); ignored by the solver
     * @param  list<PoolItem>  $pool  Ingredients the solver may use
     * @param  array{weight: float|int, pod?: float|int|null, pac?: float|int|null, total_fat?: float|int|null, slng?: float|int|null, total_solids?: float|int|null}|null  $targets
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public RecipeCategory $category,
        public array $lines = [],
        public array $pool = [],
        public ?array $targets = null,
        public array $options = [],
        public ?string $recipeId = null,
    ) {}
}
