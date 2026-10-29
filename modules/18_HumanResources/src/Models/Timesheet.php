<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

final class Timesheet extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'hr_timesheets';

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'regular_hours' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'total_hours' => 'decimal:2',
            'billable_hours' => 'decimal:2',
            'hourly_cost_rate' => 'decimal:2',
            'total_labor_cost' => 'decimal:2',
            'is_locked' => 'boolean',
            'metadata' => 'array',
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'cost_center_id');
    }
}
