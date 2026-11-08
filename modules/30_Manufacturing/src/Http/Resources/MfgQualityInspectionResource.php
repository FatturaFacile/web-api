<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgQualityInspection;

/**
 * A quality inspection with its measured results and quantities.
 *
 * @mixin MfgQualityInspection
 */
final class MfgQualityInspectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inspection_number' => $this->inspection_number,
            'quality_plan_id' => $this->quality_plan_id,
            'inspection_point' => $this->inspection_point,
            'production_order_id' => $this->production_order_id,
            'production_order_operation_id' => $this->production_order_operation_id,
            'material_movement_id' => $this->material_movement_id,
            'mfg_item_id' => $this->mfg_item_id,
            'inspector_id' => $this->inspector_id,
            'sample_size' => $this->sample_size,
            'defect_count' => $this->defect_count,
            'quantity_inspected' => $this->quantity_inspected,
            'quantity_accepted' => $this->quantity_accepted,
            'quantity_rejected' => $this->quantity_rejected,
            'status' => $this->status,
            'inspected_at' => $this->inspected_at?->toIso8601String(),
            'notes' => $this->notes,
            'characteristic_results' => $this->characteristic_results,
            'non_conformances' => MfgNonConformanceResource::collection($this->whenLoaded('nonConformances')),
        ];
    }
}
