<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ChangeRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'change_number' => $this->change_number,
            'title' => $this->title,
            'description' => $this->description,
            'change_type' => $this->change_type,
            'risk_level' => $this->risk_level,
            'priority' => $this->priority,
            'status' => $this->status,
            'service_category_id' => $this->service_category_id,
            'rollout_plan' => $this->rollout_plan,
            'rollback_plan' => $this->rollback_plan,
            'test_plan' => $this->test_plan,
            'cab_approved_at' => $this->cab_approved_at?->toIso8601String(),
            'is_locked' => (bool) $this->is_locked,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
