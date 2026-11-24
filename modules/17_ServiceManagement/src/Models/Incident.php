<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

final class Incident extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'esm_incidents';

    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
            'contained_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'post_mortem_completed' => 'boolean',
            'is_locked' => 'boolean',
            'timeline' => 'array',
            'metadata' => 'array',
        ];
    }

    public function lockByWorkflow(WorkflowInstance $instance): void
    {
        $this->update([
            'workflow_instance_id' => $instance->id,
            'is_locked' => true,
        ]);
    }

    public function isLockedByWorkflow(): bool
    {
        return (bool) $this->is_locked;
    }

    protected function description(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['summary'] ?? $value ?? '',
            set: fn ($value) => ['summary' => $value]
        );
    }

    protected function resolutionSummary(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['post_mortem_summary'] ?? $value ?? '',
            set: fn ($value) => ['post_mortem_summary' => $value]
        );
    }

    protected function isMajorIncident(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => ($attributes['severity'] ?? '') === 'P1' || ($attributes['severity'] ?? '') === 'critical'
        );
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }
}
