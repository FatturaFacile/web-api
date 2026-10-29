<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

final class WorkOrder extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'esm_work_orders';

    protected function casts(): array
    {
        return [
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'actual_start' => 'datetime',
            'actual_end' => 'datetime',
            'signed_at' => 'datetime',
            'labor_cost' => 'decimal:2',
            'material_cost' => 'decimal:2',
            'expense_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'total_billable_amount' => 'decimal:2',
            'is_billable' => 'boolean',
            'is_invoiced' => 'boolean',
            'customer_signed' => 'boolean',
            'is_locked' => 'boolean',
            'location' => 'array',
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

    protected function leadTechnicianId(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['assigned_technician_id'] ?? $value,
            set: fn ($value) => ['assigned_technician_id' => $value]
        );
    }

    protected function totalLaborCost(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => (float) ($attributes['labor_cost'] ?? $value ?? 0.0),
            set: fn ($value) => ['labor_cost' => $value]
        );
    }

    protected function totalPartsCost(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => (float) ($attributes['material_cost'] ?? $value ?? 0.0),
            set: fn ($value) => ['material_cost' => $value]
        );
    }

    protected function totalBilledAmount(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => (float) ($attributes['total_billable_amount'] ?? $value ?? 0.0),
            set: fn ($value) => ['total_billable_amount' => $value]
        );
    }

    protected function totalLaborHours(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];

                return (float) ($meta['total_labor_hours'] ?? 0.0);
            },
            set: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];
                $meta['total_labor_hours'] = (float) $value;

                return ['metadata' => json_encode($meta)];
            }
        );
    }

    protected function isInvoiceReady(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];

                return (bool) ($meta['is_invoice_ready'] ?? false);
            },
            set: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];
                $meta['is_invoice_ready'] = (bool) $value;

                return ['metadata' => json_encode($meta)];
            }
        );
    }

    protected function resolutionSummary(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['resolution_notes'] ?? $value ?? '',
            set: fn ($value) => ['resolution_notes' => $value]
        );
    }

    protected function signedByCustomerName(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['signed_by_name'] ?? $value ?? '',
            set: fn ($value) => ['signed_by_name' => $value]
        );
    }

    protected function customerSignatureUrl(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['signature_data'] ?? $value ?? '',
            set: fn ($value) => ['signature_data' => $value, 'customer_signed' => true]
        );
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(ServiceTechnician::class, 'assigned_technician_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(ServiceTeam::class, 'service_team_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class, 'work_order_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(ServiceAppointment::class, 'work_order_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(ServiceVisit::class, 'work_order_id');
    }

    public function partsConsumptions(): HasMany
    {
        return $this->hasMany(PartsConsumption::class, 'work_order_id');
    }
}
