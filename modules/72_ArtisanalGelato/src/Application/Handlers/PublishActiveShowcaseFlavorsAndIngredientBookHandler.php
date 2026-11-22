<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Application\Handlers;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\ArtisanalGelato\Application\Commands\PublishActiveShowcaseFlavorsAndIngredientBookCommand;
use Modules\ArtisanalGelato\Application\Services\GelatoRecipeService;
use Modules\ArtisanalGelato\Domain\Services\AllergenCatalog;
use Modules\ArtisanalGelato\Domain\Services\RecipeExploder;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoBalancedRecipe;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoShowcaseActiveFlavor;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoShowcaseIngredientBook;

final class PublishActiveShowcaseFlavorsAndIngredientBookHandler
{
    public const array LOCALES = ['it', 'de', 'fr', 'en'];

    public function __construct(private readonly GelatoRecipeService $recipes, private readonly RecipeExploder $exploder) {}

    public function handle(PublishActiveShowcaseFlavorsAndIngredientBookCommand $command): GelatoShowcaseIngredientBook
    {
        $slotNumbers = array_column($command->slots, 'slot_number');

        if (count($slotNumbers) !== count(array_unique($slotNumbers))) {
            throw new InvalidArgumentException('A showcase well can hold one flavor only.');
        }

        // The recipes of all wells are loaded once, as sub-recipes of a pseudo recipe, together with their sub-recipes.
        $pseudoLines = array_map(static fn (array $slot): array => ['sub_recipe_id' => $slot['recipe_id'], 'grams' => 1], $command->slots);
        [$graph, $ingredients] = $this->recipes->graph($pseudoLines);
        $exploded = [];

        foreach ($command->slots as $slot) {
            $exploded[$slot['slot_number']] = $this->exploder->explode($slot['recipe_id'], $graph, $ingredients);
        }

        $names = $this->recipeNames(array_column($command->slots, 'recipe_id'));

        return DB::transaction(function () use ($command, $exploded, $ingredients, $names): GelatoShowcaseIngredientBook {
            GelatoShowcaseActiveFlavor::query()->where('location_key', $command->locationKey)->delete();

            foreach ($command->slots as $slot) {
                GelatoShowcaseActiveFlavor::query()->create([
                    'location_key' => $command->locationKey,
                    'slot_number' => $slot['slot_number'],
                    'recipe_id' => $slot['recipe_id'],
                    'is_active' => true,
                ]);
            }

            $version = 1 + (int) GelatoShowcaseIngredientBook::query()->where('location_key', $command->locationKey)->max('version');
            $payload = [];

            foreach (self::LOCALES as $locale) {
                $allergenNames = AllergenCatalog::names($locale);
                $slots = [];

                foreach ($command->slots as $slot) {
                    $data = $exploded[$slot['slot_number']];
                    $slots[] = [
                        'slot_number' => $slot['slot_number'],
                        'recipe_id' => $slot['recipe_id'],
                        'name' => $names[$slot['recipe_id']][$locale] ?? $names[$slot['recipe_id']]['en'] ?? '',
                        'ingredients' => array_map(static function (array $row) use ($ingredients, $locale): array {
                            $node = $ingredients[$row['ingredient_id']];

                            return [
                                'ingredient_id' => $row['ingredient_id'],
                                'name' => $node['name'][$locale] ?? $node['name']['en'] ?? '',
                                'percent' => $row['percent'],
                                'allergens' => array_values($node['allergens']),
                            ];
                        }, $data['ingredients']),
                        'allergens' => array_map(static fn (string $code): array => ['code' => $code, 'name' => $allergenNames[$code]], $data['allergens']),
                        'gluten_free' => $data['gluten_free'],
                        'lactose_free' => $data['lactose_free'],
                        'vegan' => $data['vegan'],
                        'defrosted' => $data['defrosted'],
                        'defrosted_notice' => $data['defrosted'] ? AllergenCatalog::defrostedNotice($locale) : null,
                    ];
                }

                $payload[$locale] = ['locale' => $locale, 'jurisdiction' => $command->jurisdiction, 'slots' => $slots];
            }

            return GelatoShowcaseIngredientBook::query()->create([
                'location_key' => $command->locationKey,
                'jurisdiction' => $command->jurisdiction,
                'version' => $version,
                'payload' => $payload,
                'published_at' => now(),
                'published_by' => $command->publishedBy,
            ]);
        });
    }

    /**
     * @param  list<string>  $recipeIds
     * @return array<string, array<string, string>>
     */
    private function recipeNames(array $recipeIds): array
    {
        $names = [];

        foreach (GelatoBalancedRecipe::query()->whereIn('id', $recipeIds)->get() as $recipe) {
            $names[$recipe->id] = (array) $recipe->name;
        }

        return $names;
    }
}
