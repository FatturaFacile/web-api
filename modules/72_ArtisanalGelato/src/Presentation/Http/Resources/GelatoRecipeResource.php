<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoBalancedRecipe;

/**
 * @mixin GelatoBalancedRecipe
 */
final class GelatoRecipeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lines = $this->resource->relationLoaded('lines') ? $this->resource->lines->map(fn ($l): array => $l->only(['ingredient_id', 'sub_recipe_id', 'grams', 'position']))->all() : null;

        return $this->resource->only(['id', 'code', 'name', 'category', 'kind', 'batch_weight_grams', 'serving_portion_grams', 'tub_weight_grams', 'is_defrosted', 'calculated_pod_percent', 'calculated_pac_index', 'milk_fat_percent', 'other_fat_percent', 'slng_percent', 'slng_max_safe_threshold', 'total_solids_percent', 'ideal_serving_temp_celsius', 'overrun_percent', 'food_cost_per_kg', 'metrics', 'allergens', 'dietary']) + ($lines === null ? [] : ['lines' => $lines]);
    }
}
