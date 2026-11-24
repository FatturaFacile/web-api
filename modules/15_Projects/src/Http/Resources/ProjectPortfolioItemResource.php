<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\Project;

/**
 * One row of the project portfolio overview.
 *
 * @mixin Project
 */
final class ProjectPortfolioItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Project $project */
        $project = $this->resource;

        return [
            'id' => $project->id,
            'project_number' => $project->project_number,
            'name' => $project->name,
            'status' => $project->status,
            'health_status' => $project->health_status,
            'project_type' => $project->project_type,
            'billing_method' => $project->billing_method,
            'contact_id' => $project->contact_id,
            'customer_name' => $project->relationLoaded('client') ? $project->client?->company_name : null,
            'project_manager_user_id' => $project->project_manager_user_id,
            'portfolio_id' => $project->portfolio_id,
            'planned_start_date' => $project->planned_start_date?->toDateString(),
            'planned_end_date' => $project->planned_end_date?->toDateString(),
            'cup_code' => $project->cup_code,
            'cig_code' => $project->cig_code,
            'contract_value' => (string) $project->contract_value,
            'planned_hours' => (string) $project->planned_hours,
            'actual_hours' => (string) $project->actual_hours,
            'planned_cost' => (string) $project->planned_cost,
            'actual_cost' => (string) $project->actual_cost,
            'planned_revenue' => (string) $project->planned_revenue,
            'progress_percentage' => (string) $project->progress_percentage,
            'currency' => $project->currency,
        ];
    }
}
