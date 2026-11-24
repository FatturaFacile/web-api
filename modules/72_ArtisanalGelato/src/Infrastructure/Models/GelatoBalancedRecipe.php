<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Balanced recipe (base mix, flavor, sorbet, semifreddo, ganache) with its calculated physico-chemical vector.
 */
final class GelatoBalancedRecipe extends BaseEnterpriseModel
{
    protected $table = 'gelato_balanced_recipes';

    /**
     * @return HasMany<GelatoRecipeLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(GelatoRecipeLine::class, 'recipe_id');
    }

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'batch_weight_grams' => 'float',
            'serving_portion_grams' => 'float',
            'tub_weight_grams' => 'float',
            'is_defrosted' => 'boolean',
            'calculated_pod_percent' => 'float',
            'calculated_pac_index' => 'float',
            'milk_fat_percent' => 'float',
            'other_fat_percent' => 'float',
            'slng_percent' => 'float',
            'slng_max_safe_threshold' => 'float',
            'total_solids_percent' => 'float',
            'ideal_serving_temp_celsius' => 'float',
            'overrun_percent' => 'float',
            'food_cost_per_kg' => 'float',
            'metrics' => 'array',
            'allergens' => 'array',
            'dietary' => 'array',
        ];
    }
}
