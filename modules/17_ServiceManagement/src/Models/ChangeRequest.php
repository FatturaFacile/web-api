<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

final class ChangeRequest extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'esm_change_requests';

    protected function casts(): array
    {
        return [
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'implemented_at' => 'datetime',
            'validated_at' => 'datetime',
            'is_locked' => 'boolean',
            'cab_reviewers' => 'array',
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

    protected function rolloutPlan(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['implementation_plan'] ?? $value ?? '',
            set: fn ($value) => ['implementation_plan' => $value]
        );
    }

    protected function scheduledStartTime(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['scheduled_start_at'] ?? $value,
            set: fn ($value) => ['scheduled_start_at' => $value]
        );
    }

    protected function scheduledEndTime(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['scheduled_end_at'] ?? $value,
            set: fn ($value) => ['scheduled_end_at' => $value]
        );
    }

    protected function cabApprovedAt(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];

                return isset($meta['cab_approved_at']) ? Carbon::parse($meta['cab_approved_at']) : null;
            },
            set: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];
                $meta['cab_approved_at'] = (string) $value;

                return ['metadata' => json_encode($meta)];
            }
        );
    }

    public function problem(): BelongsTo
    {
        return $this->belongsTo(Problem::class, 'problem_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }
}
