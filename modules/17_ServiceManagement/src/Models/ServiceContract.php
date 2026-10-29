<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

final class ServiceContract extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'esm_service_contracts';

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'renewal_date' => 'date',
            'annual_value' => 'decimal:2',
            'auto_renew' => 'boolean',
            'is_locked' => 'boolean',
            'terms' => 'array',
            'entitlements' => 'array',
            'metadata' => 'array',
        ];
    }

    public function lockByWorkflow(WorkflowInstance $instance): void
    {
        $this->update([
            'workflow_instance_id' => $instance->id,
            'approval_status' => 'pending_approval',
            'is_locked' => true,
        ]);
    }

    public function isLockedByWorkflow(): bool
    {
        return (bool) $this->is_locked;
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['title'] ?? $value ?? '',
            set: fn ($value) => ['title' => $value]
        );
    }

    protected function contractValue(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => (float) ($attributes['annual_value'] ?? $value ?? 0.0),
            set: fn ($value) => ['annual_value' => $value]
        );
    }

    protected function contractType(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $terms = json_decode($attributes['terms'] ?? '{}', true) ?: [];

                return $terms['contract_type'] ?? 'sla';
            },
            set: function ($value, $attributes) {
                $terms = json_decode($attributes['terms'] ?? '{}', true) ?: [];
                $terms['contract_type'] = (string) $value;

                return ['terms' => json_encode($terms)];
            }
        );
    }

    protected function billingCycle(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $terms = json_decode($attributes['terms'] ?? '{}', true) ?: [];

                return $terms['billing_cycle'] ?? 'monthly';
            },
            set: function ($value, $attributes) {
                $terms = json_decode($attributes['terms'] ?? '{}', true) ?: [];
                $terms['billing_cycle'] = (string) $value;

                return ['terms' => json_encode($terms)];
            }
        );
    }

    public function supportPlan(): BelongsTo
    {
        return $this->belongsTo(SupportPlan::class, 'support_plan_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'service_contract_id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'service_contract_id');
    }
}
