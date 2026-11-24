<?php

declare(strict_types=1);

namespace Modules\Planning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Planning\Models\PlanningScenario;

/**
 * A planning scenario with the stamps of its S&OP cycle; the approval trail is included when loaded.
 *
 * @mixin PlanningScenario
 */
final class ScenarioResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'scenario_type' => $this->scenario_type,
            'base_scenario_id' => $this->base_scenario_id,
            'status' => $this->status,
            'horizon_start' => $this->horizon_start->toDateString(),
            'horizon_end' => $this->horizon_end->toDateString(),
            'submitted_by' => $this->submitted_by,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'locked_by' => $this->locked_by,
            'locked_at' => $this->locked_at?->toIso8601String(),
            'published_version' => $this->published_version,
            'published_at' => $this->published_at?->toIso8601String(),
            'lines_count' => $this->whenCounted('lines'),
            'approvals' => ApprovalResource::collection($this->whenLoaded('approvals')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
