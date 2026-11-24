<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * A leave request: pending_approval, approved, rejected or cancelled. The reason and the medical certificate reference
 * may reveal health data: they stay out of the audit ledger and out of every response outside HR roles and the
 * employee.
 *
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property Carbon|null $approved_at
 */
final class LeaveRequest extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'hr_leave_requests';

    /**
     * @var list<string>
     */
    protected $hidden = ['reason', 'medical_certificate_ref'];

    /**
     * What enters the immutable ledger: no free text and no medical reference.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditPayload(array $attributes): array
    {
        unset($attributes['reason'], $attributes['medical_certificate_ref'], $attributes['rejection_reason'], $attributes['metadata']);

        return $attributes;
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'total_hours' => 'decimal:2',
            'total_days' => 'decimal:2',
            'is_locked' => 'boolean',
            'approved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Mark the request as decided by its workflow.
     */
    public function lockByWorkflow(WorkflowInstance $instance): void
    {
        $this->update([
            'workflow_instance_id' => $instance->id,
            'status' => 'pending_approval',
            'is_locked' => true,
        ]);
    }

    /**
     * Whether a running workflow decides the request.
     */
    public function isLockedByWorkflow(): bool
    {
        return (bool) $this->is_locked;
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
