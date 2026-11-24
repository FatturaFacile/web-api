<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectCostEntry;

/**
 * API representation of a project cost entry or expense (schema ProjectsCostEntry).
 *
 * @mixin ProjectCostEntry
 */
final class ProjectCostEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectCostEntry $entry */
        $entry = $this->resource;

        return [
            'id' => $entry->id,
            'project_id' => $entry->project_id,
            'project_phase_id' => $entry->project_phase_id,
            'project_task_id' => $entry->project_task_id,
            'cost_source_type' => $entry->cost_source_type,
            'cost_source_id' => $entry->cost_source_id,
            'cost_category' => $entry->cost_category,
            'description' => $entry->description,
            'quantity' => (string) $entry->quantity,
            'uom' => $entry->uom,
            'unit_cost' => (string) $entry->unit_cost,
            'total_cost' => (string) $entry->total_cost,
            'is_billable' => $entry->is_billable,
            'invoice_id' => $entry->invoice_id,
            'incurred_date' => $entry->incurred_date->toDateString(),
            'approval_status' => $entry->approval_status,
            'rejection_reason' => $entry->rejection_reason,
            'allocations' => $this->whenLoaded('allocations'),
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }
}
