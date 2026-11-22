<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Application\Handlers;

use InvalidArgumentException;
use Modules\ArtisanalGelato\Application\Commands\BalanceGelatoRecipeParametersCommand;
use Modules\ArtisanalGelato\Application\Services\GelatoRecipeService;
use Modules\ArtisanalGelato\Domain\Services\AutoBalanceSolver;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoTechnicalIngredient;

final class BalanceGelatoRecipeParametersHandler
{
    public function __construct(private readonly GelatoRecipeService $recipes, private readonly AutoBalanceSolver $solver) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(BalanceGelatoRecipeParametersCommand $command): array
    {
        if ($command->targets === null) {
            return ['mode' => 'preview', 'metrics' => $this->recipes->preview($command->lines, $command->category, $command->options, $command->recipeId)];
        }

        $byId = GelatoTechnicalIngredient::query()->whereIn('id', array_column($command->pool, 'ingredient_id'))->get()->keyBy('id');
        $ingredients = [];

        foreach ($command->pool as $item) {
            $ingredient = $byId->get($item['ingredient_id']) ?? throw new InvalidArgumentException("Unknown ingredient {$item['ingredient_id']}.");
            $ingredients[] = [
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
                'min_grams' => $item['min_grams'] ?? null,
                'max_grams' => $item['max_grams'] ?? null,
            ];
        }

        return ['mode' => 'auto_balance'] + $this->solver->solve($ingredients, $command->targets, $command->category);
    }
}
