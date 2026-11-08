<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectMilestone;

/**
 * API representation of a milestone (schema ProjectsMilestone).
 *
 * @mixin ProjectMilestone
 */
final class ProjectMilestoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectMilestone $milestone */
        $milestone = $this->resource;

        return [
            'id' => $milestone->id,
            'project_id' => $milestone->project_id,
            'project_phase_id' => $milestone->project_phase_id,
            'name' => $milestone->name,
            'milestone_date' => $milestone->milestone_date->toDateString(),
            'billing_percentage' => (string) $milestone->billing_percentage,
            'billing_amount' => (string) $milestone->billing_amount,
            'is_completed' => $milestone->is_completed,
            'completed_at' => $milestone->completed_at?->toIso8601String(),
            'is_billed' => $milestone->is_billed,
            'invoice_id' => $milestone->invoice_id,
        ];
    }
}
