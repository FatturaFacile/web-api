<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\Project;

/**
 * API representation of a project (schema ProjectsProject).
 *
 * @mixin Project
 */
final class ProjectResource extends JsonResource
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
            'description' => $project->description,
            'project_type' => $project->project_type,
            'status' => $project->status,
            'billing_method' => $project->billing_method,
            'revenue_recognition_method' => $project->revenue_recognition_method,
            'contact_id' => $project->contact_id,
            'portfolio_id' => $project->portfolio_id,
            'parent_project_id' => $project->parent_project_id,
            'project_manager_user_id' => $project->project_manager_user_id,
            'currency' => $project->currency,
            'contract_value' => (string) $project->contract_value,
            'progress_percentage' => (string) $project->progress_percentage,
            'health_status' => $project->health_status,
            'planned_start_date' => $project->planned_start_date?->toDateString(),
            'planned_end_date' => $project->planned_end_date?->toDateString(),
            'baseline_start_date' => $project->baseline_start_date?->toDateString(),
            'baseline_end_date' => $project->baseline_end_date?->toDateString(),
            'actual_start_date' => $project->actual_start_date?->toDateString(),
            'actual_end_date' => $project->actual_end_date?->toDateString(),
            'closed_at' => $project->closed_at?->toIso8601String(),
            'phases' => $this->whenLoaded('phases'),
            'milestones' => ProjectMilestoneResource::collection($this->whenLoaded('milestones')),
            'financial_summary' => new ProjectFinancialSummaryResource($this->whenLoaded('financialSummary')),
            'created_at' => $project->created_at?->toIso8601String(),
            'updated_at' => $project->updated_at?->toIso8601String(),
        ];
    }
}
