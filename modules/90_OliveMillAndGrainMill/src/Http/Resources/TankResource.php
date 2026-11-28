<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\OliveMillAndGrainMill\Models\SianTank;

/**
 * @mixin SianTank
 */
final class TankResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'description' => $this->description,
            'material' => $this->material,
            'capacity_kg' => $this->capacity_kg,
            'capacity_l' => $this->capacity_l,
            'stock_kg' => $this->stock_kg,
            'stock_l' => $this->stock_l,
            'category' => $this->category?->value,
            'origin_designation' => $this->origin_designation?->value,
            'lot_composition' => $this->lot_composition,
            'temperature_c' => $this->temperature_c,
            'is_active' => $this->is_active,
            'grid_row' => $this->grid_row,
            'grid_col' => $this->grid_col,
            'zone_label' => $this->zone_label,
            'fill_percent' => $this->fillPercent(),
            'free_kg' => $this->freeKg(),
        ];
    }
}
