<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Facility\Models\FacilityMeter;

/**
 * A utility meter with its last reading.
 *
 * @mixin FacilityMeter
 */
final class MeterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'space_id' => $this->space_id,
            'kind' => $this->kind,
            'code' => $this->code,
            'name' => $this->name,
            'unit' => $this->unit,
            'tariff_per_unit' => $this->tariff_per_unit,
            'currency' => $this->currency,
            'rollover_value' => $this->rollover_value,
            'is_active' => $this->is_active,
            'installed_on' => $this->installed_on?->toDateString(),
            'last_read_on' => $this->last_read_on?->toDateString(),
            'last_value' => $this->last_value,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
