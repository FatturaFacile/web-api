<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node of a workflow definition: start, approval, conditional, notification or end.
 *
 * @property string $id
 * @property string $workflow_definition_id
 * @property string $node_key
 * @property string $name
 * @property string $node_type
 * @property ?string $approval_type
 * @property ?string $assignee_type
 * @property ?string $assignee_id
 * @property ?int $timeout_hours
 * @property ?string $escalation_rule_id
 * @property bool $locks_approvable_on_completion
 */
final class WorkflowNode extends BaseEnterpriseModel
{
    protected $table = 'workflow_nodes';

    protected function casts(): array
    {
        return [
            'timeout_hours' => 'integer',
            'voting_threshold_percentage' => 'decimal:2',
            'minimum_votes_required' => 'integer',
            'locks_approvable_on_completion' => 'boolean',
            'ui_metadata' => 'array',
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    /**
     * @return BelongsTo<WorkflowEscalationRule, $this>
     */
    public function escalationRule(): BelongsTo
    {
        return $this->belongsTo(WorkflowEscalationRule::class, 'escalation_rule_id');
    }

    /**
     * @return BelongsTo<BusinessCalendar, $this>
     */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(BusinessCalendar::class, 'business_calendar_id');
    }

    public function outgoingTransitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class, 'from_node_id')->orderBy('priority');
    }

    public function incomingTransitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class, 'to_node_id');
    }
}
