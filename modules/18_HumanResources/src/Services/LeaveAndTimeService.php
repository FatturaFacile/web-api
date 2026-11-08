<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services;

use Carbon\CarbonImmutable;
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
use Modules\HumanResources\Exceptions\LeaveRequestStateException;
use Modules\HumanResources\Models\AttendanceRecord;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\LeaveAccrualPolicy;
use Modules\HumanResources\Models\LeaveAccrualPosting;
use Modules\HumanResources\Models\LeaveBalance;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\HumanResources\Models\LeaveType;
use Modules\HumanResources\Models\OvertimeRule;
use Modules\HumanResources\Models\Timesheet;
use Modules\HumanResources\Support\Decimal;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * Leave balances and requests, attendance and project timesheets. Hours are decimal strings computed with bcmath;
 * balances are changed under a row lock in the transaction of the request. A leave request is approved through the
 * HR_LEAVE_APPROVAL workflow when the company has an active definition, by an HR manager otherwise, and immediately
 * when its leave type needs no approval.
 */
final readonly class LeaveAndTimeService implements LeaveAndTimeServiceInterface
{
    public const string LeaveWorkflowCode = 'HR_LEAVE_APPROVAL';

    /**
     * Leave categories drawn from an entitlement: a request beyond the available balance is refused. Sickness,
     * accident, parental, military and unpaid leave are recorded against the balance without a limit.
     *
     * @var list<string>
     */
    public const array EntitlementCategories = ['vacation', 'rol', 'study'];

    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
        private ?WorkflowEngineInterface $workflowEngine = null
    ) {}

    /**
     * Create the balance of a leave type for a year, or return the existing one.
     */
    public function initializeLeaveBalance(
        string $tenantId,
        string $employeeId,
        string $leaveTypeId,
        int $fiscalYear,
        float $entitlementHours,
        float $carryoverHours = 0.0
    ): LeaveBalance {
        return DB::transaction(function () use ($tenantId, $employeeId, $leaveTypeId, $fiscalYear, $entitlementHours, $carryoverHours): LeaveBalance {
            $existing = $this->lockedBalance($tenantId, $employeeId, $leaveTypeId, $fiscalYear);

            if ($existing !== null) {
                return $existing;
            }

            $entitlement = Decimal::of($entitlementHours);
            $carryover = Decimal::of($carryoverHours);

            return LeaveBalance::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'employee_id' => $employeeId,
                'leave_type_id' => $leaveTypeId,
                'fiscal_year' => $fiscalYear,
                'entitlement_hours' => Decimal::round($entitlement),
                'carryover_hours' => Decimal::round($carryover),
                'accrued_hours' => Decimal::round($entitlement),
                'used_hours' => '0.00',
                'pending_hours' => '0.00',
                'available_hours' => Decimal::round(Decimal::add($entitlement, $carryover)),
                'last_accrued_at' => now(),
            ]);
        });
    }

    /**
     * Accrue every balance of the year for one month. A monthly policy (or, without policy, a leave type with monthly
     * accrual) adds a twelfth of the yearly entitlement, prorated by FTE when the policy says so; a yearly policy grants
     * the whole entitlement once. The balance cap of the policy is honoured, and the accrual journal makes a second run
     * for the same month a no-op.
     */
    public function runMonthlyAccruals(string $tenantId, int $fiscalYear, ?int $month = null): int
    {
        $month ??= (int) now()->month;
        $periodStart = CarbonImmutable::create($fiscalYear, $month, 1)->startOfDay();

        return DB::transaction(function () use ($tenantId, $fiscalYear, $month, $periodStart): int {
            $balances = DB::connection()->getDriverName() === 'pgsql'
                ? LeaveBalance::fromQuery('SELECT * FROM hr_leave_balances WHERE tenant_id = ? AND fiscal_year = ? AND deleted_at IS NULL FOR UPDATE SKIP LOCKED', [$tenantId, $fiscalYear])
                : LeaveBalance::query()->where('tenant_id', $tenantId)->where('fiscal_year', $fiscalYear)->lockForUpdate()->get();

            $processed = 0;

            foreach ($balances as $balance) {
                $leaveType = LeaveType::query()->find($balance->leave_type_id);
                $employee = Employee::query()->find($balance->employee_id);

                if ($leaveType === null || $employee === null) {
                    continue;
                }

                $policy = $this->policyFor($leaveType, $employee, $periodStart);
                [$increment, $period] = $this->accrualIncrement($leaveType, $employee, $policy, $fiscalYear, $month);

                if ($increment === null || LeaveAccrualPosting::query()->where('leave_balance_id', $balance->id)->where('period', $period)->exists()) {
                    continue;
                }

                $available = Decimal::sub(Decimal::add(Decimal::add((string) $balance->accrued_hours, $increment), (string) $balance->carryover_hours), Decimal::add((string) $balance->used_hours, (string) $balance->pending_hours));

                if ($policy?->max_balance_hours !== null && Decimal::cmp($available, (string) $policy->max_balance_hours) > 0) {
                    $increment = Decimal::max('0', Decimal::sub($increment, Decimal::sub($available, (string) $policy->max_balance_hours)));
                    $available = (string) $policy->max_balance_hours;
                }

                $balance->update([
                    'accrued_hours' => Decimal::round(Decimal::add((string) $balance->accrued_hours, $increment)),
                    'available_hours' => Decimal::round($available),
                    'last_accrued_at' => now(),
                ]);

                LeaveAccrualPosting::query()->create([
                    'tenant_id' => $tenantId,
                    'leave_balance_id' => $balance->id,
                    'accrual_policy_id' => $policy?->id,
                    'period' => $period,
                    'hours' => Decimal::round($increment),
                ]);

                $this->publishLiability($employee, $fiscalYear, $available, $tenantId);
                $processed++;
            }

            return $processed;
        });
    }

    /**
     * Reserve the hours and start the approval of a leave request.
     */
    public function submitLeaveRequest(SubmitLeaveRequestDTO $dto, ?string $actorUserId = null): LeaveRequest
    {
        return DB::transaction(function () use ($dto, $actorUserId): LeaveRequest {
            $employee = Employee::query()->where('id', $dto->employeeId)->lockForUpdate()->firstOrFail();
            $leaveType = LeaveType::query()->findOrFail($dto->leaveTypeId);
            $hours = Decimal::of($dto->totalHours);

            if (Decimal::cmp($hours, '0') <= 0) {
                throw new InvalidArgumentException('The requested hours must be positive.');
            }

            if ($dto->endDate < $dto->startDate) {
                throw new InvalidArgumentException('The leave cannot end before it starts.');
            }

            $overlapping = LeaveRequest::query()
                ->where('tenant_id', $dto->tenantId)
                ->where('employee_id', $dto->employeeId)
                ->whereIn('status', ['pending_approval', 'approved'])
                ->whereDate('start_date', '<=', $dto->endDate)
                ->whereDate('end_date', '>=', $dto->startDate)
                ->exists();

            if ($overlapping) {
                throw new InvalidArgumentException("Overlapping or duplicate leave request detected for employee [{$employee->employee_number}] between {$dto->startDate} and {$dto->endDate}.");
            }

            $fiscalYear = (int) substr($dto->startDate, 0, 4);
            $balance = $this->lockedBalance($dto->tenantId, $dto->employeeId, $dto->leaveTypeId, $fiscalYear);

            if ($balance === null) {
                $created = $this->initializeLeaveBalance($dto->tenantId, $dto->employeeId, $dto->leaveTypeId, $fiscalYear, (float) $leaveType->annual_entitlement_hours);
                $balance = LeaveBalance::query()->whereKey($created->id)->lockForUpdate()->firstOrFail();
            }

            if (in_array($leaveType->category, self::EntitlementCategories, true) && Decimal::cmp((string) $balance->available_hours, $hours) < 0) {
                throw new InvalidArgumentException("Insufficient leave balance. Available: {$balance->available_hours}h, Requested: ".Decimal::round($hours).'h.');
            }

            $balance->update([
                'pending_hours' => Decimal::round(Decimal::add((string) $balance->pending_hours, $hours)),
                'available_hours' => Decimal::round(Decimal::sub((string) $balance->available_hours, $hours)),
            ]);

            $requestNumber = 'LVR-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
            $dailyHours = $employee->activeContract !== null && Decimal::cmp((string) $employee->activeContract->weekly_hours, '0') > 0
                ? Decimal::div((string) $employee->activeContract->weekly_hours, '5')
                : '8';

            $leaveRequest = LeaveRequest::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $dto->tenantId,
                'company_id' => $employee->company_id ?? $dto->tenantId,
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'request_number' => $requestNumber,
                'start_date' => $dto->startDate,
                'end_date' => $dto->endDate,
                'total_hours' => Decimal::round($hours),
                'total_days' => Decimal::round(Decimal::div($hours, $dailyHours)),
                'status' => 'pending_approval',
                'reason' => $dto->reason,
                'medical_certificate_ref' => $dto->medicalCertificateRef,
            ]);

            $this->auditLedger->record(
                action: 'HR_LEAVE_REQUESTED',
                model: $leaveRequest,
                payloadBefore: null,
                payloadAfter: self::auditView($leaveRequest),
                moduleCode: 'MODULE_18_HR'
            );

            $this->dispatcher->publishAndDispatch(new LeaveRequestedEvent(
                leaveRequestId: (string) $leaveRequest->id,
                requestNumber: $requestNumber,
                employeeId: (string) $employee->id,
                leaveTypeId: (string) $leaveType->id,
                startDate: $dto->startDate,
                endDate: $dto->endDate,
                totalHours: (float) Decimal::round($hours),
                tenantId: $dto->tenantId,
                companyId: (string) ($employee->company_id ?? $dto->tenantId),
                eventId: (string) Str::uuid7(),
                occurredAt: now()->toIso8601String(),
            ));

            if (! $leaveType->requires_approval) {
                return $this->approveLeaveRequest($leaveRequest, $actorUserId);
            }

            if ($this->workflowEngine !== null && $this->hasActiveDefinition($dto->tenantId)) {
                $instance = $this->workflowEngine->start(new StartWorkflowDTO(
                    workflowCode: self::LeaveWorkflowCode,
                    approvable: $leaveRequest,
                    contextSnapshot: ['request_number' => $requestNumber, 'total_hours' => Decimal::round($hours), 'start_date' => $dto->startDate, 'end_date' => $dto->endDate],
                    tenantId: $dto->tenantId,
                    companyId: $dto->tenantId,
                    initiatorUserId: $actorUserId !== null && Str::isUuid($actorUserId) ? $actorUserId : null,
                ));
                $leaveRequest->refresh();

                if ($leaveRequest->status === 'pending_approval' && ! in_array($instance->status, ['approved', 'completed', 'rejected', 'cancelled'], true)) {
                    $leaveRequest->lockByWorkflow($instance);
                }
            }

            return $leaveRequest->fresh() ?? $leaveRequest;
        });
    }

    /**
     * Approve a pending request: the reserved hours become used hours. A request under a running workflow is decided
     * by the workflow only.
     */
    public function approveLeaveRequest(LeaveRequest $leaveRequest, ?string $approverUserId = null): LeaveRequest
    {
        return DB::transaction(function () use ($leaveRequest, $approverUserId): LeaveRequest {
            $locked = LeaveRequest::query()->whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'approved') {
                return $locked;
            }

            $this->assertDecidable($locked);
            $before = self::auditView($locked);
            $balance = $this->lockedBalance((string) $locked->tenant_id, (string) $locked->employee_id, (string) $locked->leave_type_id, (int) $locked->start_date->format('Y'))
                ?? throw new InvalidArgumentException('The leave balance of the request is missing.');
            $hours = (string) $locked->total_hours;

            $balance->update([
                'pending_hours' => Decimal::round(Decimal::max('0', Decimal::sub((string) $balance->pending_hours, $hours))),
                'used_hours' => Decimal::round(Decimal::add((string) $balance->used_hours, $hours)),
            ]);

            $approverId = $approverUserId !== null && Str::isUuid($approverUserId) ? $approverUserId : null;
            $locked->update(['status' => 'approved', 'is_locked' => false, 'approved_by_user_id' => $approverId, 'approved_at' => now()]);

            $this->auditLedger->record(action: 'HR_LEAVE_APPROVED', model: $locked, payloadBefore: $before, payloadAfter: self::auditView($locked), moduleCode: 'MODULE_18_HR');

            $this->dispatcher->publishAndDispatch(new LeaveApprovedEvent(
                leaveRequestId: (string) $locked->id,
                requestNumber: (string) $locked->request_number,
                employeeId: (string) $locked->employee_id,
                leaveTypeId: (string) $locked->leave_type_id,
                totalHours: (float) $hours,
                approvedByUserId: $approverId,
                tenantId: (string) $locked->tenant_id,
                companyId: (string) ($locked->company_id ?? $locked->tenant_id),
                eventId: (string) Str::uuid7(),
                occurredAt: now()->toIso8601String(),
            ));

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Reject a pending request: the reserved hours return to the balance.
     */
    public function rejectLeaveRequest(LeaveRequest $leaveRequest, string $reason, ?string $approverUserId = null): LeaveRequest
    {
        return DB::transaction(function () use ($leaveRequest, $reason): LeaveRequest {
            $locked = LeaveRequest::query()->whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'rejected') {
                return $locked;
            }

            $this->assertDecidable($locked);
            $before = self::auditView($locked);
            $this->releaseHours($locked, 'pending_hours');
            $locked->update(['status' => 'rejected', 'is_locked' => false, 'rejection_reason' => $reason]);

            $this->auditLedger->record(action: 'HR_LEAVE_REJECTED', model: $locked, payloadBefore: $before, payloadAfter: self::auditView($locked), moduleCode: 'MODULE_18_HR');

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Withdraw a pending request (its workflow is cancelled) or an approved one that has not started yet.
     */
    public function cancelLeaveRequest(LeaveRequest $leaveRequest, string $reason, ?string $actorUserId = null): LeaveRequest
    {
        return DB::transaction(function () use ($leaveRequest, $reason, $actorUserId): LeaveRequest {
            $locked = LeaveRequest::query()->whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();
            $before = self::auditView($locked);

            if ($locked->status === 'pending_approval') {
                $instance = $locked->workflow_instance_id === null ? null : WorkflowInstance::query()->find($locked->workflow_instance_id);

                if ($instance !== null && $this->workflowEngine !== null && in_array($instance->status, ['pending', 'in_progress'], true)) {
                    $this->workflowEngine->cancel($instance, $reason);
                }

                $this->releaseHours($locked, 'pending_hours');
            } elseif ($locked->status === 'approved' && $locked->start_date->isAfter(now()->startOfDay())) {
                $this->releaseHours($locked, 'used_hours');
            } else {
                throw new LeaveRequestStateException('Only a pending request or an approved request that has not started yet can be cancelled.');
            }

            $locked->update([
                'status' => 'cancelled',
                'is_locked' => false,
                'metadata' => array_merge($locked->metadata ?? [], ['cancelled_at' => now()->toIso8601String(), 'cancelled_by' => $actorUserId, 'cancellation_reason' => $reason]),
            ]);

            $this->auditLedger->record(action: 'HR_LEAVE_CANCELLED', model: $locked, payloadBefore: $before, payloadAfter: self::auditView($locked), moduleCode: 'MODULE_18_HR');

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Record the attendance hours of a day. Clock times are not invented: they come from the clock events.
     */
    public function recordAttendance(
        string $tenantId,
        string $employeeId,
        string $workDate,
        float $regularHours = 8.0,
        float $overtimeHours = 0.0,
        ?string $projectId = null
    ): AttendanceRecord {
        return DB::transaction(fn (): AttendanceRecord => AttendanceRecord::updateOrCreate(
            ['tenant_id' => $tenantId, 'employee_id' => $employeeId, 'work_date' => $workDate],
            [
                'company_id' => $tenantId,
                'regular_hours' => Decimal::round(Decimal::of($regularHours)),
                'overtime_hours' => Decimal::round(Decimal::of($overtimeHours)),
                'status' => 'present',
                'project_id' => $projectId,
            ]
        ));
    }

    /**
     * Record project hours. The labour cost uses the hourly cost of the employee (or of the active contract) and, for
     * overtime hours, the premium of the highest-priority overtime rule of the company valid on the day; without a
     * rule overtime is costed at the plain rate.
     */
    public function submitTimesheet(RecordTimesheetDTO $dto, ?string $actorUserId = null): Timesheet
    {
        return DB::transaction(function () use ($dto): Timesheet {
            $employee = Employee::query()->with('activeContract')->findOrFail($dto->employeeId);
            $hourlyCost = Decimal::of((string) $employee->hourly_cost_rate);

            if (Decimal::cmp($hourlyCost, '0') <= 0 && $employee->activeContract !== null) {
                $hourlyCost = Decimal::of((string) ($employee->activeContract->loaded_hourly_cost ?: $employee->activeContract->hourly_rate));
            }

            $regular = Decimal::of($dto->regularHours);
            $overtime = Decimal::of($dto->overtimeHours);
            $premium = (string) (OvertimeRule::query()->where('tenant_id', $dto->tenantId)->effectiveOn($dto->workDate)->orderByDesc('priority')->value('premium_percent') ?? '0');
            $overtimeFactor = Decimal::add('1', Decimal::div($premium, '100'));
            $total = Decimal::add($regular, $overtime);
            $laborCost = Decimal::add(Decimal::mul($regular, $hourlyCost), Decimal::mul(Decimal::mul($overtime, $hourlyCost), $overtimeFactor));

            $timesheet = Timesheet::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $dto->tenantId,
                'company_id' => $employee->company_id ?? $dto->tenantId,
                'employee_id' => $employee->id,
                'project_id' => $dto->projectId,
                'task_id' => $dto->taskId,
                'cost_center_id' => $dto->costCenterId ?? $employee->cost_center_id,
                'timesheet_number' => 'TS-'.now()->format('Ymd').'-'.strtoupper(Str::random(5)),
                'work_date' => $dto->workDate,
                'regular_hours' => Decimal::round($regular),
                'overtime_hours' => Decimal::round($overtime),
                'total_hours' => Decimal::round($total),
                'billable_hours' => $dto->billableHours > 0 ? Decimal::round(Decimal::of($dto->billableHours)) : Decimal::round($total),
                'hourly_cost_rate' => Decimal::round($hourlyCost),
                'total_labor_cost' => Decimal::round($laborCost),
                'status' => 'submitted',
                'description' => $dto->description,
            ]);

            $this->auditLedger->record(action: 'HR_TIMESHEET_SUBMITTED', model: $timesheet, payloadBefore: null, payloadAfter: $timesheet->toArray(), moduleCode: 'MODULE_18_HR');

            return $timesheet;
        });
    }

    /**
     * Approve a timesheet and publish its labour cost.
     */
    public function approveTimesheet(Timesheet $timesheet, ?string $approverUserId = null): Timesheet
    {
        return DB::transaction(function () use ($timesheet, $approverUserId): Timesheet {
            $locked = Timesheet::query()->whereKey($timesheet->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'approved') {
                return $locked;
            }

            $before = $locked->toArray();
            $locked->update([
                'status' => 'approved',
                'is_locked' => false,
                'approved_by_user_id' => $approverUserId !== null && Str::isUuid($approverUserId) ? $approverUserId : null,
            ]);

            $this->auditLedger->record(action: 'HR_TIMESHEET_APPROVED', model: $locked, payloadBefore: $before, payloadAfter: $locked->toArray(), moduleCode: 'MODULE_18_HR');

            $this->dispatcher->publishAndDispatch(new LaborCostReadyEvent(
                timesheetId: (string) $locked->id,
                employeeId: (string) $locked->employee_id,
                projectId: $locked->project_id ? (string) $locked->project_id : null,
                costCenterId: $locked->cost_center_id ? (string) $locked->cost_center_id : null,
                totalHours: (float) $locked->total_hours,
                hourlyCostRate: (float) $locked->hourly_cost_rate,
                totalLaborCost: (float) $locked->total_labor_cost,
                workDate: $locked->work_date->format('Y-m-d'),
                tenantId: (string) $locked->tenant_id,
                companyId: (string) ($locked->company_id ?? $locked->tenant_id),
                eventId: (string) Str::uuid7(),
                occurredAt: now()->toIso8601String(),
            ));

            return $locked->fresh() ?? $locked;
        });
    }

    private function lockedBalance(string $tenantId, string $employeeId, string $leaveTypeId, int $fiscalYear): ?LeaveBalance
    {
        return LeaveBalance::query()
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('fiscal_year', $fiscalYear)
            ->lockForUpdate()
            ->first();
    }

    /**
     * The hours of the request go back to the available balance, from the pending or the used bucket.
     */
    private function releaseHours(LeaveRequest $request, string $bucket): void
    {
        $balance = $this->lockedBalance((string) $request->tenant_id, (string) $request->employee_id, (string) $request->leave_type_id, (int) $request->start_date->format('Y'));

        if ($balance === null) {
            return;
        }

        $hours = (string) $request->total_hours;
        $balance->update([
            $bucket => Decimal::round(Decimal::max('0', Decimal::sub((string) $balance->{$bucket}, $hours))),
            'available_hours' => Decimal::round(Decimal::add((string) $balance->available_hours, $hours)),
        ]);
    }

    private function assertDecidable(LeaveRequest $request): void
    {
        if ($request->status !== 'pending_approval') {
            throw new LeaveRequestStateException("A {$request->status} leave request cannot be decided.");
        }

        if ($request->isLockedByWorkflow()) {
            throw new LeaveRequestStateException('The leave request is being approved through its workflow: decide it there.');
        }
    }

    private function hasActiveDefinition(string $tenantId): bool
    {
        return WorkflowDefinition::query()->where('tenant_id', $tenantId)->where('code', self::LeaveWorkflowCode)->where('is_active', true)->exists();
    }

    /**
     * The policy that applies to the employee on the day: one for the employment type before a generic one.
     */
    private function policyFor(LeaveType $leaveType, Employee $employee, CarbonImmutable $day): ?LeaveAccrualPolicy
    {
        return LeaveAccrualPolicy::query()
            ->where('leave_type_id', $leaveType->id)
            ->where('is_active', true)
            ->whereDate('valid_from', '<=', $day->format('Y-m-d'))
            ->where(static fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day->format('Y-m-d')))
            ->where(static fn ($query) => $query->whereNull('employment_type')->orWhere('employment_type', $employee->employment_type))
            ->orderByRaw('employment_type IS NULL')
            ->orderByDesc('valid_from')
            ->first();
    }

    /**
     * @return array{0: string|null, 1: string} the hours to add (null: nothing accrues) and the journal period
     */
    private function accrualIncrement(LeaveType $leaveType, Employee $employee, ?LeaveAccrualPolicy $policy, int $fiscalYear, int $month): array
    {
        $monthPeriod = sprintf('%04d-%02d', $fiscalYear, $month);

        if ($policy === null) {
            return $leaveType->accrual_frequency === 'monthly'
                ? [Decimal::div((string) $leaveType->annual_entitlement_hours, '12'), $monthPeriod]
                : [null, $monthPeriod];
        }

        $entitlement = (string) $policy->annual_entitlement_hours;

        if ($policy->prorate_by_fte) {
            $entitlement = Decimal::mul($entitlement, Decimal::of((string) ($employee->fte_ratio ?? '1')));
        }

        return $policy->accrual_frequency === LeaveAccrualPolicy::FrequencyYearly
            ? [$entitlement, sprintf('%04d-YR', $fiscalYear)]
            : [Decimal::div($entitlement, '12'), $monthPeriod];
    }

    /**
     * The value of the unused hours, published only when the employee has an hourly cost: none is assumed.
     */
    private function publishLiability(Employee $employee, int $fiscalYear, string $available, string $tenantId): void
    {
        $hourlyRate = Decimal::of((string) $employee->hourly_cost_rate);

        if (Decimal::cmp($available, '0') <= 0 || Decimal::cmp($hourlyRate, '0') <= 0) {
            return;
        }

        $this->dispatcher->publishAndDispatch(new LeaveLiabilityReadyEvent(
            employeeId: (string) $employee->id,
            fiscalYear: $fiscalYear,
            unusedHours: (float) Decimal::round($available),
            hourlyRate: (float) Decimal::round($hourlyRate),
            liabilityAmount: (float) Decimal::round(Decimal::mul($available, $hourlyRate)),
            tenantId: $tenantId,
            companyId: $tenantId,
            eventId: (string) Str::uuid7(),
            occurredAt: now()->toIso8601String(),
        ));
    }

    /**
     * What of a leave request enters the immutable ledger: no reason text and no medical certificate reference, which
     * may reveal health data.
     *
     * @return array<string, mixed>
     */
    private static function auditView(LeaveRequest $request): array
    {
        return collect($request->attributesToArray())->except(['reason', 'medical_certificate_ref', 'metadata'])->all();
    }
}
