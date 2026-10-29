<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

final class EmploymentContract extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'hr_employment_contracts';

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'probation_end_date' => 'date',
            'weekly_hours' => 'decimal:2',
            'fte_percentage' => 'decimal:2',
            'base_annual_salary' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'loaded_hourly_cost' => 'decimal:2',
            'is_active' => 'boolean',
            'is_locked' => 'boolean',
            'terms' => 'array',
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

    public function jobGrade(): BelongsTo
    {
        return $this->belongsTo(JobGrade::class, 'job_grade_id');
    }
}
