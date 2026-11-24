<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * Technical ingredient with its sucrose-referenced indexes (POD, PAC), composition per 100 g, cost per kg and allergens.
 */
final class GelatoTechnicalIngredient extends BaseEnterpriseModel
{
    // The seeded catalog is global (read only); a tenant adds its own ingredients next to it.
    use AllowsGlobalRecords;

    protected $table = 'gelato_technical_ingredients';

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'pod' => 'float',
            'pac' => 'float',
            'milk_fat_percent' => 'float',
            'other_fat_percent' => 'float',
            'slng_percent' => 'float',
            'total_solids_percent' => 'float',
            'water_percent' => 'float',
            'sugar_percent' => 'float',
            'fiber_percent' => 'float',
            'protein_percent' => 'float',
            'kcal_per_100g' => 'float',
            'cost_per_kg' => 'float',
            'brix' => 'float',
            'cocoa_butter_percent' => 'float',
            'cocoa_mass_percent' => 'float',
            'allergens' => 'array',
            'is_vegan' => 'boolean',
            'is_defrosted' => 'boolean',
            'is_active' => 'boolean',
            'stock_unit_grams' => 'float',
        ];
    }
}
