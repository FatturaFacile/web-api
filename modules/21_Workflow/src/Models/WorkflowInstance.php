<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class WorkflowInstance extends BaseEnterpriseModel
{
    protected $table = 'workflow_instances';

    protected function casts(): array
    {
        return [
            'workflow_definition_version' => 'integer',
            'workflow_definition_snapshot' => 'array',
            'context_snapshot' => 'array',
            'locked_fiscal_document' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    public function currentNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowNode::class, 'current_node_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowInstanceStep::class, 'workflow_instance_id');
    }

    public function activeSteps(): HasMany
    {
        return $this->steps()->where('status', 'pending');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(WorkflowComment::class, 'workflow_instance_id')->orderBy('created_at', 'asc');
    }

    public function auditEntries(): HasMany
    {
        return $this->hasMany(WorkflowAuditEntry::class, 'workflow_instance_id')->orderBy('created_at', 'asc');
    }
}
