<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WorkflowTransition extends BaseEnterpriseModel
{
    protected $table = 'workflow_transitions';

    protected function casts(): array
    {
        return [
            'condition_rules' => 'array',
            'priority' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<WorkflowNode, $this>
     */
    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowNode::class, 'from_node_id');
    }

    /**
     * @return BelongsTo<WorkflowNode, $this>
     */
    public function toNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowNode::class, 'to_node_id');
    }

    /**
     * @return BelongsTo<WorkflowDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }
}
