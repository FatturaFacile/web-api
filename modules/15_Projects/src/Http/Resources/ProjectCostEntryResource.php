<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectCostEntry;

/**
 * @mixin ProjectCostEntry
 */
final class ProjectCostEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'project_phase_id' => $this->project_phase_id,
            'project_task_id' => $this->project_task_id,
            'cost_source_type' => $this->cost_source_type,
            'cost_source_id' => $this->cost_source_id,
            'cost_category' => $this->cost_category,
            'quantity' => (float) $this->quantity,
            'uom' => $this->uom,
            'unit_cost' => (float) $this->unit_cost,
            'total_cost' => (float) $this->total_cost,
            'is_billable' => (bool) $this->is_billable,
            'is_invoiced' => (bool) $this->is_invoiced,
            'incurred_date' => $this->incurred_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
