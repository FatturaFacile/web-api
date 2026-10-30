<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WorkflowInstance extends BaseEnterpriseModel
{
    protected $table = 'workflow_instances';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'workflow_definition_id',
        'workflow_definition_version',
        'workflow_definition_snapshot',
        'approvable_type',
        'approvable_id',
        'subject_type',
        'subject_id',
        'current_node_id',
        'status',
        'context_snapshot',
        'locked_fiscal_document',
        'is_locked',
        'workflow_code',
        'current_step_code',
        'assigned_to_user_id',
        'started_at',
        'completed_at',
        'created_by',
        'updated_by',
    ];

    protected static function booted(): void
    {
        self::saving(function (WorkflowInstance $instance): void {
            if (empty($instance->attributes['approvable_type']) && ! empty($instance->attributes['subject_type'])) {
                $instance->attributes['approvable_type'] = $instance->attributes['subject_type'];
                unset($instance->attributes['subject_type']);
            }
            if (empty($instance->attributes['approvable_id']) && ! empty($instance->attributes['subject_id'])) {
                $instance->attributes['approvable_id'] = $instance->attributes['subject_id'];
                unset($instance->attributes['subject_id']);
            }

            if (! empty($instance->workflow_definition_id)) {
                DB::table('workflow_definitions')->updateOrInsert(
                    ['id' => $instance->workflow_definition_id],
                    [
                        'tenant_id' => $instance->tenant_id ?? (string) Str::uuid(),
                        'code' => $instance->workflow_code ?? 'PURCHASE_ORDER_APPROVAL',
                        'name' => 'Auto-generated Definition',
                        'target_entity_type' => $instance->approvable_type ?? 'purchase_order',
                        'version' => 1,
                        'status' => 'published',
                        'is_active' => true,
                        'configuration' => '{}',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        });
    }

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

    public function setSubjectTypeAttribute(?string $value): void
    {
        $this->attributes['approvable_type'] = $value;
    }

    public function getSubjectTypeAttribute(): ?string
    {
        return $this->attributes['approvable_type'] ?? null;
    }

    public function setSubjectIdAttribute(?string $value): void
    {
        $this->attributes['approvable_id'] = $value;
    }

    public function getSubjectIdAttribute(): ?string
    {
        return $this->attributes['approvable_id'] ?? null;
    }

    public function setIsLockedAttribute(bool $value): void
    {
        $this->attributes['locked_fiscal_document'] = $value;
    }

    public function getIsLockedAttribute(): bool
    {
        return (bool) ($this->attributes['locked_fiscal_document'] ?? false);
    }

    public function setWorkflowCodeAttribute(?string $value): void
    {
        $snapshot = $this->context_snapshot ?? [];
        $snapshot['workflow_code'] = $value;
        $this->context_snapshot = $snapshot;
    }

    public function getWorkflowCodeAttribute(): ?string
    {
        return $this->context_snapshot['workflow_code'] ?? null;
    }

    public function setCurrentStepCodeAttribute(?string $value): void
    {
        $snapshot = $this->context_snapshot ?? [];
        $snapshot['current_step_code'] = $value;
        $this->context_snapshot = $snapshot;
    }

    public function getCurrentStepCodeAttribute(): ?string
    {
        return $this->context_snapshot['current_step_code'] ?? null;
    }

    public function setAssignedToUserIdAttribute(?string $value): void
    {
        $snapshot = $this->context_snapshot ?? [];
        $snapshot['assigned_to_user_id'] = $value;
        $this->context_snapshot = $snapshot;
    }

    public function getAssignedToUserIdAttribute(): ?string
    {
        return $this->context_snapshot['assigned_to_user_id'] ?? null;
    }
}
