<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Workflow\Models\WorkflowTransition;

/**
 * @mixin WorkflowTransition
 */
final class WorkflowTransitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_definition_id' => $this->workflow_definition_id,
            'from_node_id' => $this->from_node_id,
            'to_node_id' => $this->to_node_id,
            'action_trigger' => $this->action_trigger,
            'priority' => $this->priority,
            'condition_rules' => $this->condition_rules,
        ];
    }
}
