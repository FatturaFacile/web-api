<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DairyCheeseManufacturing\Models\PricingGrid;

/**
 * @mixin PricingGrid
 */
final class PricingGridResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'jurisdiction' => $this->jurisdiction, 'cheese_type' => $this->cheese_type,
            'base_price_per_kg' => $this->base_price_per_kg, 'cheese_milk_supplement_per_kg' => $this->cheese_milk_supplement_per_kg, 'dop_premium_per_kg' => $this->dop_premium_per_kg,
            'valid_from' => $this->valid_from->toDateString(), 'valid_to' => $this->valid_to?->toDateString(), 'is_active' => (bool) $this->is_active,
            'is_template' => $this->tenant_id === null,
            'tiers' => array_values(array_map(static fn (array $t): array => [
                'component' => $t['component'], 'from_value' => $t['from_value'] ?? null, 'to_value' => $t['to_value'] ?? null, 'adjustment_per_kg' => $t['adjustment_per_kg'],
            ], (array) $this->tiers)),
        ];
    }
}
