<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\HumanResources\Contracts\LeaveAndTimeServiceInterface;
use Modules\HumanResources\DTOs\RecordTimesheetDTO;
use Modules\HumanResources\DTOs\SubmitLeaveRequestDTO;
use Modules\HumanResources\Events\LaborCostReadyEvent;
use Modules\HumanResources\Events\LeaveApprovedEvent;
use Modules\HumanResources\Events\LeaveLiabilityReadyEvent;
use Modules\HumanResources\Events\LeaveRequestedEvent;
use Modules\HumanResources\Models\AttendanceRecord;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\LeaveBalance;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\HumanResources\Models\LeaveType;
use Modules\HumanResources\Models\Timesheet;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Models\WorkflowDefinition;

final readonly class LeaveAndTimeService implements LeaveAndTimeServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
        private ?WorkflowEngineInterface $workflowEngine = null
    ) {}

    public function initializeLeaveBalance(
        string $tenantId,
        string $employeeId,
        string $leaveTypeId,
        int $fiscalYear,
        float $entitlementHours,
        float $carryoverHours = 0.0
    ): LeaveBalance {
        return DB::transaction(function () use ($tenantId, $employeeId, $leaveTypeId, $fiscalYear, $entitlementHours, $carryoverHours) {
            $existing = LeaveBalance::where('tenant_id', $tenantId)
                ->where('employee_id', $employeeId)
                ->where('leave_type_id', $leaveTypeId)
                ->where('fiscal_year', $fiscalYear)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return LeaveBalance::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'employee_id' => $employeeId,
                'leave_type_id' => $leaveTypeId,
                'fiscal_year' => $fiscalYear,
                'entitlement_hours' => $entitlementHours,
                'carryover_hours' => $carryoverHours,
                'accrued_hours' => $entitlementHours,
                'used_hours' => 0.00,
                'pending_hours' => 0.00,
                'available_hours' => round($entitlementHours + $carryoverHours, 2),
                'last_accrued_at' => now(),
            ]);
        });
    }

    public function runMonthlyAccruals(string $tenantId, int $fiscalYear): int
    {
        return DB::transaction(function () use ($tenantId, $fiscalYear) {
            $query = LeaveBalance::where('tenant_id', $tenantId)
                ->where('fiscal_year', $fiscalYear);

            if (DB::connection()->getDriverName() === 'pgsql') {
                $balances = LeaveBalance::fromQuery(
                    'SELECT * FROM hr_leave_balances WHERE tenant_id = ? AND fiscal_year = ? AND deleted_at IS NULL FOR UPDATE SKIP LOCKED',
                    [$tenantId, $fiscalYear]
                );
            } else {
                $balances = $query->lockForUpdate()->get();
            }

            $processed = 0;
            foreach ($balances as $balance) {
                $leaveType = LeaveType::find($balance->leave_type_id);
                if ($leaveType === null || $leaveType->accrual_frequency !== 'monthly') {
                    continue;
                }

                $monthlyIncrement = round(((float) $leaveType->annual_entitlement_hours) / 12.0, 2);
                $newAccrued = round((float) $balance->accrued_hours + $monthlyIncrement, 2);
                $newAvailable = round(
                    $newAccrued + (float) $balance->carryover_hours - (float) $balance->used_hours - (float) $balance->pending_hours,
                    2
                );

                $balance->update([
                    'accrued_hours' => $newAccrued,
                    'available_hours' => $newAvailable,
                    'last_accrued_at' => now(),
                ]);

                $employee = Employee::find($balance->employee_id);
                if ($employee !== null && $newAvailable > 0.0) {
                    $hourlyRate = (float) $employee->hourly_cost_rate > 0 ? (float) $employee->hourly_cost_rate : 25.00;
                    $liability = round($newAvailable * $hourlyRate, 2);

                    $liabilityEvent = new LeaveLiabilityReadyEvent(
                        employeeId: (string) $employee->id,
                        fiscalYear: $fiscalYear,
                        unusedHours: $newAvailable,
                        hourlyRate: $hourlyRate,
                        liabilityAmount: $liability,
                        tenantId: $tenantId,
                        companyId: $tenantId
                    );
                    $this->dispatcher->publish($liabilityEvent);
                    event($liabilityEvent);
                }

                $processed++;
            }

            return $processed;
        });
    }

    public function submitLeaveRequest(SubmitLeaveRequestDTO $dto, ?string $actorUserId = null): LeaveRequest
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $employee = Employee::where('id', $dto->employeeId)->lockForUpdate()->firstOrFail();
            $leaveType = LeaveType::findOrFail($dto->leaveTypeId);

            // Prevent duplicate overlapping leave requests
            $overlapping = LeaveRequest::where('tenant_id', $dto->tenantId)
                ->where('employee_id', $dto->employeeId)
                ->whereIn('status', ['pending_approval', 'approved'])
                ->whereDate('start_date', '<=', $dto->endDate)
                ->whereDate('end_date', '>=', $dto->startDate)
                ->exists();

            if ($overlapping) {
                throw new InvalidArgumentException("Overlapping or duplicate leave request detected for employee [{$employee->employee_number}] between {$dto->startDate} and {$dto->endDate}.");
            }

            $fiscalYear = (int) date('Y', strtotime($dto->startDate));

            $balance = LeaveBalance::where('tenant_id', $dto->tenantId)
                ->where('employee_id', $dto->employeeId)
                ->where('leave_type_id', $dto->leaveTypeId)
                ->where('fiscal_year', $fiscalYear)
                ->lockForUpdate()
                ->first();

            if ($balance === null) {
                $balance = $this->initializeLeaveBalance(
                    $dto->tenantId,
                    $dto->employeeId,
                    $dto->leaveTypeId,
                    $fiscalYear,
                    (float) $leaveType->annual_entitlement_hours
                );
                $balance = LeaveBalance::where('id', $balance->id)->lockForUpdate()->firstOrFail();
            }

            if ($leaveType->category !== 'unpaid' && (float) $balance->available_hours < $dto->totalHours) {
                throw new InvalidArgumentException("Insufficient leave balance. Available: {$balance->available_hours}h, Requested: {$dto->totalHours}h.");
            }

            // Reserve pending hours
            $balance->update([
                'pending_hours' => round((float) $balance->pending_hours + $dto->totalHours, 2),
                'available_hours' => round((float) $balance->available_hours - $dto->totalHours, 2),
            ]);

            $reqNumber = 'LVR-'.date('Ymd').'-'.strtoupper(Str::random(5));

            $leaveRequest = LeaveRequest::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $dto->tenantId,
                'company_id' => $employee->company_id ?? $dto->tenantId,
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'request_number' => $reqNumber,
                'start_date' => $dto->startDate,
                'end_date' => $dto->endDate,
                'total_hours' => $dto->totalHours,
                'total_days' => round($dto->totalHours / 8.0, 2),
                'status' => 'pending_approval',
                'reason' => $dto->reason,
                'medical_certificate_ref' => $dto->medicalCertificateRef,
            ]);

            if ($this->workflowEngine !== null) {
                $hasDef = WorkflowDefinition::where('tenant_id', $dto->tenantId)
                    ->where('code', 'HR_LEAVE_APPROVAL')
                    ->where('is_active', true)
                    ->exists();

                if ($hasDef) {
                    $initiatorId = ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : (string) Str::uuid7();
                    $instance = $this->workflowEngine->startWorkflow(new StartWorkflowDTO(
                        workflowCode: 'HR_LEAVE_APPROVAL',
                        approvable: $leaveRequest,
                        contextSnapshot: [
                            'request_number' => $reqNumber,
                            'total_hours' => $dto->totalHours,
                        ],
                        tenantId: $dto->tenantId,
                        companyId: $dto->tenantId,
                        initiatorUserId: $initiatorId
                    ));
                    $leaveRequest->lockByWorkflow($instance);
                }
            }

            $this->auditLedger->record(
                action: 'HR_LEAVE_REQUESTED',
                model: $leaveRequest,
                payloadBefore: null,
                payloadAfter: $leaveRequest->fresh()->toArray(),
                moduleCode: 'MODULE_18_HR'
            );

            $event = new LeaveRequestedEvent(
                leaveRequestId: (string) $leaveRequest->id,
                requestNumber: $reqNumber,
                employeeId: (string) $employee->id,
                leaveTypeId: (string) $leaveType->id,
                startDate: $dto->startDate,
                endDate: $dto->endDate,
                totalHours: $dto->totalHours,
                tenantId: $dto->tenantId,
                companyId: (string) ($employee->company_id ?? $dto->tenantId)
            );
            $this->dispatcher->publish($event);
            event($event);

            return $leaveRequest->fresh();
        });
    }

    public function approveLeaveRequest(LeaveRequest $leaveRequest, ?string $approverUserId = null): LeaveRequest
    {
        return DB::transaction(function () use ($leaveRequest, $approverUserId) {
            $locked = LeaveRequest::where('id', $leaveRequest->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'approved') {
                return $locked;
            }

            $before = $locked->toArray();
            $fiscalYear = (int) $locked->start_date->format('Y');

            $balance = LeaveBalance::where('tenant_id', $locked->tenant_id)
                ->where('employee_id', $locked->employee_id)
                ->where('leave_type_id', $locked->leave_type_id)
                ->where('fiscal_year', $fiscalYear)
                ->lockForUpdate()
                ->firstOrFail();

            $hours = (float) $locked->total_hours;
            $newPending = max(0.0, round((float) $balance->pending_hours - $hours, 2));
            $newUsed = round((float) $balance->used_hours + $hours, 2);

            $balance->update([
                'pending_hours' => $newPending,
                'used_hours' => $newUsed,
            ]);

            $approverUuid = ! empty($approverUserId) && Str::isUuid($approverUserId) ? $approverUserId : null;

            $locked->update([
                'status' => 'approved',
                'is_locked' => false,
                'approved_by_user_id' => $approverUuid,
                'approved_at' => now(),
            ]);

            $this->auditLedger->record(
                action: 'HR_LEAVE_APPROVED',
                model: $locked,
                payloadBefore: $before,
                payloadAfter: $locked->fresh()->toArray(),
                moduleCode: 'MODULE_18_HR'
            );

            $event = new LeaveApprovedEvent(
                leaveRequestId: (string) $locked->id,
                requestNumber: (string) $locked->request_number,
                employeeId: (string) $locked->employee_id,
                leaveTypeId: (string) $locked->leave_type_id,
                totalHours: $hours,
                approvedByUserId: $approverUuid,
                tenantId: (string) $locked->tenant_id,
                companyId: (string) ($locked->company_id ?? $locked->tenant_id)
            );
            $this->dispatcher->publish($event);
            event($event);

            return $locked->fresh();
        });
    }

    public function rejectLeaveRequest(LeaveRequest $leaveRequest, string $reason, ?string $approverUserId = null): LeaveRequest
    {
        return DB::transaction(function () use ($leaveRequest, $reason) {
            $locked = LeaveRequest::where('id', $leaveRequest->id)->lockForUpdate()->firstOrFail();
            $before = $locked->toArray();
            $fiscalYear = (int) $locked->start_date->format('Y');

            $balance = LeaveBalance::where('tenant_id', $locked->tenant_id)
                ->where('employee_id', $locked->employee_id)
                ->where('leave_type_id', $locked->leave_type_id)
                ->where('fiscal_year', $fiscalYear)
                ->lockForUpdate()
                ->first();

            if ($balance !== null) {
                $hours = (float) $locked->total_hours;
                $balance->update([
                    'pending_hours' => max(0.0, round((float) $balance->pending_hours - $hours, 2)),
                    'available_hours' => round((float) $balance->available_hours + $hours, 2),
                ]);
            }

            $locked->update([
                'status' => 'rejected',
                'is_locked' => false,
                'rejection_reason' => $reason,
            ]);

            $this->auditLedger->record(
                action: 'HR_LEAVE_REJECTED',
                model: $locked,
                payloadBefore: $before,
                payloadAfter: $locked->fresh()->toArray(),
                moduleCode: 'MODULE_18_HR'
            );

            return $locked->fresh();
        });
    }

    public function recordAttendance(
        string $tenantId,
        string $employeeId,
        string $workDate,
        float $regularHours = 8.0,
        float $overtimeHours = 0.0,
        ?string $projectId = null
    ): AttendanceRecord {
        return DB::transaction(function () use ($tenantId, $employeeId, $workDate, $regularHours, $overtimeHours, $projectId) {
            return AttendanceRecord::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'employee_id' => $employeeId,
                    'work_date' => $workDate,
                ],
                [
                    'company_id' => $tenantId,
                    'clock_in_at' => $workDate.' 08:30:00',
                    'clock_out_at' => $workDate.' 17:30:00',
                    'break_minutes' => 60,
                    'regular_hours' => $regularHours,
                    'overtime_hours' => $overtimeHours,
                    'status' => 'present',
                    'project_id' => $projectId,
                ]
            );
        });
    }

    public function submitTimesheet(RecordTimesheetDTO $dto, ?string $actorUserId = null): Timesheet
    {
        return DB::transaction(function () use ($dto) {
            $employee = Employee::findOrFail($dto->employeeId);
            $hourlyCost = (float) $employee->hourly_cost_rate > 0 ? (float) $employee->hourly_cost_rate : 30.00;
            $totalHours = round($dto->regularHours + $dto->overtimeHours, 2);
            // Overtime carries a 25% premium in labor cost calculation
            $totalLaborCost = round(
                ($dto->regularHours * $hourlyCost) + ($dto->overtimeHours * $hourlyCost * 1.25),
                2
            );

            $timesheet = Timesheet::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $dto->tenantId,
                'company_id' => $employee->company_id ?? $dto->tenantId,
                'employee_id' => $employee->id,
                'project_id' => $dto->projectId,
                'task_id' => $dto->taskId,
                'cost_center_id' => $dto->costCenterId ?? $employee->cost_center_id,
                'timesheet_number' => 'TS-'.date('Ymd').'-'.strtoupper(Str::random(5)),
                'work_date' => $dto->workDate,
                'regular_hours' => $dto->regularHours,
                'overtime_hours' => $dto->overtimeHours,
                'total_hours' => $totalHours,
                'billable_hours' => $dto->billableHours > 0 ? $dto->billableHours : $totalHours,
                'hourly_cost_rate' => $hourlyCost,
                'total_labor_cost' => $totalLaborCost,
                'status' => 'submitted',
                'description' => $dto->description,
            ]);

            $this->auditLedger->record(
                action: 'HR_TIMESHEET_SUBMITTED',
                model: $timesheet,
                payloadBefore: null,
                payloadAfter: $timesheet->toArray(),
                moduleCode: 'MODULE_18_HR'
            );

            return $timesheet;
        });
    }

    public function approveTimesheet(Timesheet $timesheet, ?string $approverUserId = null): Timesheet
    {
        return DB::transaction(function () use ($timesheet, $approverUserId) {
            $locked = Timesheet::where('id', $timesheet->id)->lockForUpdate()->firstOrFail();
            $before = $locked->toArray();
            $approverUuid = ! empty($approverUserId) && Str::isUuid($approverUserId) ? $approverUserId : null;

            $locked->update([
                'status' => 'approved',
                'is_locked' => false,
                'approved_by_user_id' => $approverUuid,
            ]);

            $this->auditLedger->record(
                action: 'HR_TIMESHEET_APPROVED',
                model: $locked,
                payloadBefore: $before,
                payloadAfter: $locked->fresh()->toArray(),
                moduleCode: 'MODULE_18_HR'
            );

            $laborEvent = new LaborCostReadyEvent(
                timesheetId: (string) $locked->id,
                employeeId: (string) $locked->employee_id,
                projectId: $locked->project_id ? (string) $locked->project_id : null,
                costCenterId: $locked->cost_center_id ? (string) $locked->cost_center_id : null,
                totalHours: (float) $locked->total_hours,
                hourlyCostRate: (float) $locked->hourly_cost_rate,
                totalLaborCost: (float) $locked->total_labor_cost,
                workDate: $locked->work_date->format('Y-m-d'),
                tenantId: (string) $locked->tenant_id,
                companyId: (string) ($locked->company_id ?? $locked->tenant_id)
            );
            $this->dispatcher->publish($laborEvent);
            event($laborEvent);

            return $locked->fresh();
        });
    }
}
