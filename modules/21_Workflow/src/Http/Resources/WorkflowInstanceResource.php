<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * @mixin WorkflowInstance
 */
final class WorkflowInstanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_definition_id' => $this->workflow_definition_id,
            'workflow_definition_version' => $this->workflow_definition_version,
            'approvable_type' => $this->approvable_type,
            'approvable_id' => $this->approvable_id,
            'current_node_id' => $this->current_node_id,
            'status' => $this->status,
            'locked_fiscal_document' => $this->locked_fiscal_document,
            'context_snapshot' => $this->context_snapshot,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'steps' => WorkflowInstanceStepResource::collection($this->whenLoaded('steps')),
        ];
    }
}
