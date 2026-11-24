<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * Eloquent model of the maintenance work order records of the asset management module.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $asset_id
 * @property string|null $plan_id
 * @property string|null $work_order_number
 * @property string|null $maintenance_type
 * @property string|null $priority
 * @property string|null $status
 * @property Carbon|null $scheduled_date
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property string|null $downtime_hours
 * @property string|null $labor_cost
 * @property string|null $parts_cost
 * @property string|null $external_service_cost
 * @property string|null $total_cost
 * @property bool $capitalize_as_upgrade
 * @property string|null $condition_after_maintenance
 * @property string|null $assigned_technician_id
 * @property string|null $workflow_instance_id
 * @property bool $is_locked
 * @property string|null $problem_description
 * @property string|null $resolution_notes
 * @property array<string, mixed>|null $parts_consumed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $downtime_started_at
 * @property Carbon|null $downtime_ended_at
 * @property string|null $estimated_cost
 * @property string|null $meter_reading_at_completion
 * @property Carbon|null $cancelled_at
 * @property string|null $journal_entry_id
 */
final class MaintenanceWorkOrder extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'eam_maintenance_work_orders';

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'downtime_hours' => 'decimal:2',
            'labor_cost' => 'decimal:2',
            'parts_cost' => 'decimal:2',
            'external_service_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'capitalize_as_upgrade' => 'boolean',
            'is_locked' => 'boolean',
            'parts_consumed' => 'array',
            'downtime_started_at' => 'datetime',
            'downtime_ended_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'estimated_cost' => 'decimal:2',
            'meter_reading_at_completion' => 'decimal:2',
        ];
    }

    public function lockByWorkflow(WorkflowInstance $instance): void
    {
        $this->update([
            'workflow_instance_id' => $instance->id,
            'status' => 'pending_approval',
            'is_locked' => true,
        ]);
    }

    public function isLockedByWorkflow(): bool
    {
        return (bool) $this->is_locked;
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class, 'plan_id');
    }

    public function assignedTechnician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_technician_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(MaintenanceActivity::class, 'work_order_id');
    }

    /**
     * Spare parts consumed from the inventory by the work order.
     *
     * @return HasMany<WorkOrderPart, $this>
     */
    public function parts(): HasMany
    {
        return $this->hasMany(WorkOrderPart::class, 'work_order_id');
    }
}
