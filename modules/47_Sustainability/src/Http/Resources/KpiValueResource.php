<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sustainability\Models\EsgKpiValue;
use Modules\Sustainability\Services\KpiService;

/**
 * The value of an indicator in a reporting period, with whether it meets the target of the indicator.
 *
 * @mixin EsgKpiValue
 */
final class KpiValueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kpi_definition_id' => $this->kpi_definition_id,
            'reporting_period_id' => $this->reporting_period_id,
            'value' => $this->value,
            'data_quality' => $this->data_quality,
            'notes' => $this->notes,
            'recorded_by' => $this->recorded_by,
            'kpi' => $this->whenLoaded('definition', fn (): KpiDefinitionResource => new KpiDefinitionResource($this->definition)),
            'target_met' => $this->relationLoaded('definition') ? app(KpiService::class)->targetMet($this->definition, (string) $this->value) : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
