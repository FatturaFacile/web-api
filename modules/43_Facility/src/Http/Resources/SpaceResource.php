<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Facility\Models\FacilitySpace;

/**
 * A floor or space with its place in the hierarchy.
 *
 * @mixin FacilitySpace
 */
final class SpaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'parent_id' => $this->parent_id,
            'kind' => $this->kind,
            'code' => $this->code,
            'name' => $this->name,
            'floor_level' => $this->floor_level,
            'capacity' => $this->capacity,
            'area_sqm' => $this->area_sqm,
            'is_bookable' => $this->is_bookable,
            'is_active' => $this->is_active,
            'amenities' => $this->amenities ?? [],
            'notes' => $this->notes,
            'building' => $this->whenLoaded('building', fn (): array => ['id' => $this->building->id, 'code' => $this->building->code, 'name' => $this->building->name]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
