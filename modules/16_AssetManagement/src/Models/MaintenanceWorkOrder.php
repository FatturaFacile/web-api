<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

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
}
