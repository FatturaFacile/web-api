<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One thing a person (or a role) has to decide on a node of an instance.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $workflow_instance_id
 * @property string $workflow_node_id
 * @property ?string $assigned_user_id
 * @property ?string $assigned_role
 * @property string $status pending, approved, rejected, timed_out, skipped or cancelled
 * @property int $tier_level
 * @property ?Carbon $due_at
 * @property ?Carbon $reminded_at
 * @property ?Carbon $escalated_at
 * @property ?Carbon $completed_at
 * @property ?Carbon $sla_breached_at
 * @property int $escalation_level
 * @property ?string $escalated_to_user_id
 * @property ?Carbon $created_at
 */
final class WorkflowInstanceStep extends BaseEnterpriseModel
{
    protected $table = 'workflow_instance_steps';

    /**
     * Calculated columns of the grid (portable SQL, see App\Support\Http\GridColumns).
     *
     * `state` is what the inbox shows for a pending step: `escalated`, `overdue` (past its time limit) or `pending`.
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            'state' => "CASE WHEN workflow_instance_steps.escalated_at IS NOT NULL THEN 'escalated' WHEN workflow_instance_steps.due_at IS NOT NULL AND workflow_instance_steps.due_at < CURRENT_TIMESTAMP THEN 'overdue' ELSE 'pending' END",
        ];
    }

    protected function casts(): array
    {
        return [
            'tier_level' => 'integer',
            'due_at' => 'datetime',
            'reminded_at' => 'datetime',
            'escalated_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WorkflowInstance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    /**
     * @return BelongsTo<WorkflowNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(WorkflowNode::class, 'workflow_node_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class, 'workflow_instance_step_id');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(ApprovalToken::class, 'workflow_instance_step_id');
    }
}
