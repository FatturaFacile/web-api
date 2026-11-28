<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Beekeeping\Models\Apiary;

/**
 * @mixin Apiary
 */
final class ApiaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'country' => $this->country,
            'municipality' => $this->municipality,
            'province_canton' => $this->province_canton,
            'registry_code' => $this->registry_code,
            'apiary_type' => $this->apiary_type,
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,
            'altitude_m' => $this->altitude_m,
            'foraging_radius_m' => $this->foraging_radius_m,
            'flora_notes' => $this->flora_notes,
            'blooming_calendar' => $this->blooming_calendar,
            'weather_notes' => $this->weather_notes,
            'is_active' => $this->is_active,
            'hives_count' => $this->whenCounted('hives'),
        ];
    }
}
