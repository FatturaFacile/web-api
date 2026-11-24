<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Employee;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * Mod 074 Leave / Sick / Accident / Special Absence request.
 * Sensitive medical certificate and claim fields are excluded from the immutable AuditLedger payload.
 *
 * @property string $employee_id
 * @property string|null $department_id
 * @property string|null $policy_id
 * @property string|null $ledger_id
 * @property string|null $hr_leave_request_id
 * @property string $request_number
 * @property string $country
 * @property string $leave_type
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property string|null $half_day_or_hourly_duration
 * @property string $duration_unit
 * @property string $requested_days
 * @property string $requested_hours
 * @property string $status
 * @property string|null $inps_medical_certificate_protocol_number
 * @property string|null $suva_claim_number
 * @property string|null $inail_claim_number
 * @property string|null $medical_certificate_doc_id
 * @property CarbonImmutable|null $certificate_issue_date
 * @property bool|null $is_accident_occupational
 * @property bool $is_fault_absence
 * @property bool $is_pregnancy_related
 * @property int|null $ijm_ktg_waiting_days
 * @property string|null $ipg_eo_allowance_code
 * @property string|null $legge_104_beneficiary_ref
 * @property array<string, mixed>|null $indemnity_breakdown
 * @property array<string, mixed>|null $staffing_check_result
 * @property string|null $workflow_instance_id
 * @property bool $is_locked
 * @property string|null $approved_by_user_id
 * @property CarbonImmutable|null $approved_at
 * @property string|null $rejection_reason
 * @property string|null $notes
 * @property array<string, mixed>|null $metadata
 */
final class LeaveRequest extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'lm_leave_requests';

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'requested_days' => 'decimal:2',
            'requested_hours' => 'decimal:2',
            'certificate_issue_date' => 'immutable_date',
            'is_accident_occupational' => 'boolean',
            'is_fault_absence' => 'boolean',
            'is_pregnancy_related' => 'boolean',
            'ijm_ktg_waiting_days' => 'integer',
            'indemnity_breakdown' => 'array',
            'staffing_check_result' => 'array',
            'is_locked' => 'boolean',
            'approved_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Exclude sensitive health/certificate identifiers from the immutable audit ledger.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditPayload(array $attributes): array
    {
        unset(
            $attributes['inps_medical_certificate_protocol_number'],
            $attributes['suva_claim_number'],
            $attributes['inail_claim_number'],
            $attributes['medical_certificate_doc_id'],
            $attributes['legge_104_beneficiary_ref'],
            $attributes['notes'],
            $attributes['rejection_reason'],
            $attributes['metadata'],
        );

        return $attributes;
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

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /**
     * @return BelongsTo<LeaveEntitlementPolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(LeaveEntitlementPolicy::class, 'policy_id');
    }

    /**
     * @return BelongsTo<EmployeeLeaveAccrualLedger, $this>
     */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(EmployeeLeaveAccrualLedger::class, 'ledger_id');
    }
}
