<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

final class EmployeeLifecycleEvent extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'hr_employee_lifecycle_events';

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'from_salary' => 'decimal:2',
            'to_salary' => 'decimal:2',
            'is_locked' => 'boolean',
            'snapshot' => 'array',
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
