<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Workflow\Models\WorkflowInstanceStep;

/**
 * @mixin WorkflowInstanceStep
 */
final class WorkflowInstanceStepResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_instance_id' => $this->workflow_instance_id,
            'workflow_node_id' => $this->workflow_node_id,
            'assigned_user_id' => $this->assigned_user_id,
            'assigned_role' => $this->assigned_role,
            'status' => $this->status,
            'tier_level' => $this->tier_level,
            'due_at' => $this->due_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'decisions' => ApprovalDecisionResource::collection($this->whenLoaded('decisions')),
        ];
    }
}
