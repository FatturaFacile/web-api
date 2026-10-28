<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectBudget;

/**
 * @mixin ProjectBudget
 */
final class ProjectBudgetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'current_version_number' => $this->current_version_number,
            'status' => $this->status,
            'total_revenue_budget' => (float) $this->total_revenue_budget,
            'total_cost_budget' => (float) $this->total_cost_budget,
            'breakdown' => [
                'labour' => (float) $this->labour_budget,
                'material' => (float) $this->material_budget,
                'supplier' => (float) $this->supplier_budget,
                'fleet' => (float) $this->fleet_budget,
                'expense' => (float) $this->expense_budget,
                'contingency' => (float) $this->contingency_budget,
            ],
            'projected_margin_amount' => (float) $this->projected_margin_amount,
            'projected_margin_percentage' => (float) $this->projected_margin_percentage,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'lines' => $this->whenLoaded('lines'),
        ];
    }
}
