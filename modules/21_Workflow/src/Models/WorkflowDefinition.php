<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WorkflowDefinition extends BaseEnterpriseModel
{
    protected $table = 'workflow_definitions';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
            'configuration' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(WorkflowNode::class, 'workflow_definition_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class, 'workflow_definition_id');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class, 'workflow_definition_id');
    }

    public function getStartNode(): ?WorkflowNode
    {
        return $this->nodes()->where('node_type', 'start')->first();
    }
}
