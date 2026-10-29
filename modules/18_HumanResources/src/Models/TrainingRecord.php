<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

final class TrainingRecord extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'hr_training_records';

    protected function casts(): array
    {
        return [
            'assigned_date' => 'date',
            'scheduled_date' => 'date',
            'completed_date' => 'date',
            'score' => 'decimal:2',
            'cost_amount' => 'decimal:2',
            'is_locked' => 'boolean',
            'certificate_issued' => 'boolean',
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

    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class, 'course_id');
    }
}
