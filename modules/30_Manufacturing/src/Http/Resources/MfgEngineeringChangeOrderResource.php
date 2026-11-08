<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgEngineeringChangeOrder;

/**
 * An engineering change with its approval trail and the component changes it carries.
 *
 * @mixin MfgEngineeringChangeOrder
 */
final class MfgEngineeringChangeOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'eco_number' => $this->eco_number,
            'title' => $this->title,
            'reason' => $this->reason,
            'description' => $this->description,
            'change_type' => $this->change_type,
            'status' => $this->status,
            'bom_id' => $this->bom_id,
            'resulting_bom_id' => $this->resulting_bom_id,
            'effective_date' => $this->effective_date?->toDateString(),
            'requested_by' => $this->requested_by,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_by' => $this->rejected_by,
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'implemented_at' => $this->implemented_at?->toIso8601String(),
            'change_payload' => $this->change_payload,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
