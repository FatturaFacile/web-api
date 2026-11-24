<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgQualityNonConformance;

/**
 * A non-conformance with its disposition and resolution.
 *
 * @mixin MfgQualityNonConformance
 */
final class MfgNonConformanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nc_number' => $this->nc_number,
            'quality_inspection_id' => $this->quality_inspection_id,
            'production_order_id' => $this->production_order_id,
            'production_order_operation_id' => $this->production_order_operation_id,
            'mfg_item_id' => $this->mfg_item_id,
            'severity' => $this->severity,
            'defect_type' => $this->defect_type,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'disposition' => $this->disposition,
            'corrective_action' => $this->corrective_action,
            'status' => $this->status,
            'reported_by' => $this->reported_by,
            'resolved_by' => $this->resolved_by,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
