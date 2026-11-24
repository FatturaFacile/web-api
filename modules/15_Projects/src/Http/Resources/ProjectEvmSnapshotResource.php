<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectEvmSnapshot;

/**
 * API representation of an earned value snapshot (schema ProjectsEvmSnapshot).
 *
 * @mixin ProjectEvmSnapshot
 */
final class ProjectEvmSnapshotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectEvmSnapshot $snapshot */
        $snapshot = $this->resource;

        return [
            'id' => $snapshot->id,
            'snapshot_date' => $snapshot->snapshot_date->toDateString(),
            'percent_complete' => (string) $snapshot->percent_complete,
            'planned_percent_complete' => (string) $snapshot->planned_percent_complete,
            'budget_at_completion' => (string) $snapshot->budget_at_completion,
            'planned_value' => (string) $snapshot->planned_value,
            'earned_value' => (string) $snapshot->earned_value,
            'actual_cost' => (string) $snapshot->actual_cost,
            'cost_variance' => (string) $snapshot->cost_variance,
            'schedule_variance' => (string) $snapshot->schedule_variance,
            'cost_performance_index' => $snapshot->cost_performance_index,
            'schedule_performance_index' => $snapshot->schedule_performance_index,
            'estimate_at_completion' => (string) $snapshot->estimate_at_completion,
            'estimate_to_complete' => (string) $snapshot->estimate_to_complete,
            'variance_at_completion' => (string) $snapshot->variance_at_completion,
            'health_status' => $snapshot->health_status,
        ];
    }
}
