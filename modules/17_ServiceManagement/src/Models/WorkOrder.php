<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
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
            'labor_cost' => 'decimal:2',
            'material_cost' => 'decimal:2',
            'expense_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'total_billable_amount' => 'decimal:2',
            'is_billable' => 'boolean',
            'is_invoiced' => 'boolean',
            'customer_signed' => 'boolean',
            'signed_at' => 'datetime',
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

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    public function serviceTeam(): BelongsTo
    {
        return $this->belongsTo(ServiceTeam::class, 'service_team_id');
    }

    public function assignedTechnician(): BelongsTo
    {
        return $this->belongsTo(ServiceTechnician::class, 'assigned_technician_id');
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

    public function csatSurveys(): HasMany
    {
        return $this->hasMany(CSATSurvey::class, 'work_order_id');
    }
}
