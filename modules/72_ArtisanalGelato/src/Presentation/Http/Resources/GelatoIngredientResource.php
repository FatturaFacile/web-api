<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoTechnicalIngredient;

/**
 * @mixin GelatoTechnicalIngredient
 */
final class GelatoIngredientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->only(['id', 'tenant_id', 'code', 'name', 'category', 'pod', 'pac', 'milk_fat_percent', 'other_fat_percent', 'slng_percent', 'total_solids_percent', 'water_percent', 'sugar_percent', 'solute_class', 'fiber_percent', 'protein_percent', 'kcal_per_100g', 'cost_per_kg', 'brix', 'cocoa_butter_percent', 'cocoa_mass_percent', 'allergens', 'is_vegan', 'is_defrosted', 'is_active']);
    }
}
