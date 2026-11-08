<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectBudget;

/**
 * API representation of a project budget (schema ProjectsBudget). The category report is added by the controller.
 *
 * @mixin ProjectBudget
 */
final class ProjectBudgetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectBudget $budget */
        $budget = $this->resource;

        return [
            'id' => $budget->id,
            'project_id' => $budget->project_id,
            'status' => $budget->status,
            'current_version_number' => $budget->current_version_number,
            'total_revenue_budget' => (string) $budget->total_revenue_budget,
            'total_cost_budget' => (string) $budget->total_cost_budget,
            'categories' => collect(ProjectBudget::CategoryColumns)->map(static fn (string $column): string => (string) $budget->getAttribute($column))->all(),
            'projected_margin_amount' => (string) $budget->projected_margin_amount,
            'projected_margin_percentage' => (string) $budget->projected_margin_percentage,
            'approved_by' => $budget->approved_by,
            'approved_at' => $budget->approved_at?->toIso8601String(),
            'lines' => $this->whenLoaded('lines'),
            'versions' => $this->whenLoaded('versions', fn () => $budget->versions->map(static fn ($version): array => [
                'version_number' => $version->version_number,
                'revision_reason' => $version->revision_reason,
                'total_cost_budget' => (string) $version->total_cost_budget,
                'total_revenue_budget' => (string) $version->total_revenue_budget,
                'is_baseline' => $version->is_baseline,
                'approved_by' => $version->approved_by,
                'created_at' => $version->created_at?->toIso8601String(),
            ])->all()),
        ];
    }
}
