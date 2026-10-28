<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
