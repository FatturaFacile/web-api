<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sustainability\Models\EsgActivityRecord;

/**
 * One line of activity data with the emission factor frozen on it.
 *
 * @mixin EsgActivityRecord
 */
final class ActivityRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'emission_factor_id' => $this->emission_factor_id,
            'activity_date' => $this->activity_date->toDateString(),
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'activity_type' => $this->activity_type,
            'scope' => $this->scope,
            'category' => $this->category,
            'factor_code' => $this->factor_code,
            'factor_version' => $this->factor_version,
            'factor_kg_co2e_per_unit' => $this->factor_kg_co2e_per_unit,
            'factor_source' => $this->factor_source,
            'co2e_kg' => $this->co2e_kg,
            'data_quality' => $this->data_quality,
            'site' => $this->site,
            'reference' => $this->reference,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'notes' => $this->notes,
            'recorded_by' => $this->recorded_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
