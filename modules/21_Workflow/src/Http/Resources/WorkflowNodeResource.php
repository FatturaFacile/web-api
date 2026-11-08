<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Workflow\Models\WorkflowNode;

/**
 * @mixin WorkflowNode
 */
final class WorkflowNodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_definition_id' => $this->workflow_definition_id,
            'node_key' => $this->node_key,
            'name' => $this->name,
            'node_type' => $this->node_type,
            'approval_type' => $this->approval_type,
            'assignee_type' => $this->assignee_type,
            'assignee_id' => $this->assignee_id,
            'timeout_hours' => $this->timeout_hours,
            'escalation_rule_id' => $this->escalation_rule_id,
            'ui_metadata' => $this->ui_metadata,
        ];
    }
}
