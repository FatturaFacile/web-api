<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgQualityInspection;

/**
 * @mixin MfgQualityInspection
 */
final class MfgQualityInspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inspection_number' => $this->inspection_number,
            'quality_plan_id' => $this->quality_plan_id,
            'production_order_id' => $this->production_order_id,
            'operation_id' => $this->operation_id,
            'status' => $this->status,
            'sample_size' => $this->sample_size,
            'defect_count' => $this->defect_count,
            'inspected_at' => $this->inspected_at?->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}
