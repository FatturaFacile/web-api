<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\Project;

/**
 * @mixin Project
 */
final class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'company_id' => $this->company_id,
            'portfolio_id' => $this->portfolio_id,
            'contact_id' => $this->contact_id,
            'project_number' => $this->project_number,
            'name' => $this->name,
            'description' => $this->description,
            'project_type' => $this->project_type,
            'status' => $this->status,
            'billing_method' => $this->billing_method,
            'planned_start_date' => $this->planned_start_date?->toDateString(),
            'planned_end_date' => $this->planned_end_date?->toDateString(),
            'actual_start_date' => $this->actual_start_date?->toDateString(),
            'actual_end_date' => $this->actual_end_date?->toDateString(),
            'project_manager_user_id' => $this->project_manager_user_id,
            'currency' => $this->currency,
            'contract_value' => (float) $this->contract_value,
            'progress_percentage' => (float) $this->progress_percentage,
            'health_status' => $this->health_status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
