<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectWbsNode;

/**
 * API representation of a WBS node with its schedule (early/late dates, floats, criticality).
 *
 * @mixin ProjectWbsNode
 */
final class ProjectWbsNodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectWbsNode $node */
        $node = $this->resource;

        return [
            'id' => $node->id,
            'project_id' => $node->project_id,
            'parent_node_id' => $node->parent_node_id,
            'wbs_code' => $node->wbs_code,
            'title' => $node->title,
            'node_type' => $node->node_type,
            'status' => $node->status,
            'required_role' => $node->required_role,
            'planned_hours' => (string) $node->planned_hours,
            'actual_hours' => (string) $node->actual_hours,
            'planned_cost' => (string) $node->planned_cost,
            'actual_cost' => (string) $node->actual_cost,
            'planned_revenue' => (string) $node->planned_revenue,
            'start_date' => $node->start_date?->toDateString(),
            'end_date' => $node->end_date?->toDateString(),
            'percent_complete' => (string) $node->percent_complete,
            'sort_order' => $node->sort_order,
            'early_start' => $node->early_start?->toDateString(),
            'early_finish' => $node->early_finish?->toDateString(),
            'late_start' => $node->late_start?->toDateString(),
            'late_finish' => $node->late_finish?->toDateString(),
            'total_float_days' => $node->total_float_days,
            'free_float_days' => $node->free_float_days,
            'is_critical' => $node->is_critical,
        ];
    }
}
