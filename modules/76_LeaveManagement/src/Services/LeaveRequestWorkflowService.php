<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Services;

use Carbon\CarbonImmutable;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Employee;
use Modules\LeaveManagement\Domain\Enums\LeaveCountry;
use Modules\LeaveManagement\Domain\Enums\LeaveRequestStatus;
use Modules\LeaveManagement\Domain\Enums\LeaveTypeCode;
use Modules\LeaveManagement\Domain\Enums\LeaveUnitMode;
use Modules\LeaveManagement\Domain\Events\LeaveAbsenceApprovedEvent;
use Modules\LeaveManagement\Domain\Events\LeaveAbsenceCancelledEvent;
use Modules\LeaveManagement\Domain\Events\LeaveAbsenceRejectedEvent;
use Modules\LeaveManagement\Domain\Events\LeaveAbsenceSubmittedEvent;
use Modules\LeaveManagement\Domain\Events\SickLeaveInpsRegisteredEvent;
use Modules\LeaveManagement\Domain\ValueObjects\InpsProtocolNumber;
use Modules\LeaveManagement\Exceptions\LeaveManagementException;
use Modules\LeaveManagement\Models\EmployeeLeaveAccrualLedger;
use Modules\LeaveManagement\Models\LeaveEntitlementPolicy;
use Modules\LeaveManagement\Models\LeaveRequest;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * Core transactional workflow for Mod 074 leave requests, INPS PUC medical registration,
 * SUVA/INAIL accident registration, staffing checks, Mod 006 approval integration, and ledger updates.
 */
final readonly class LeaveRequestWorkflowService
{
    public const string WORKFLOW_CODE = 'HR_LEAVE_APPROVAL';

    public function __construct(
        private LeaveAccrualService $accrualService,
        private DepartmentStaffingService $staffingService,
        private LeaveIndemnityCalculatorService $indemnityCalculator,
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
        private ?WorkflowEngineInterface $workflowEngine = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function submitLeaveRequest(string $tenantId, array $payload, ?string $actorUserId = null): LeaveRequest
    {
        return DB::transaction(function () use ($tenantId, $payload, $actorUserId): LeaveRequest {
            $employee = Employee::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail((string) $payload['employee_id']);

            $country = LeaveCountry::from(strtoupper((string) ($payload['country'] ?? $this->accrualService->resolveEmployeeCountry($employee))));
            $leaveType = LeaveTypeCode::from((string) $payload['leave_type']);
            $startDate = CarbonImmutable::parse((string) $payload['start_date'])->startOfDay();
            $endDate = CarbonImmutable::parse((string) $payload['end_date'])->startOfDay();

            if ($endDate->lt($startDate)) {
                throw LeaveManagementException::validation('end_date_before_start');
            }

            $this->assertNoOverlappingAbsence($tenantId, (string) $employee->id, $startDate, $endDate);

            $policy = $this->resolvePolicy($tenantId, $country, $leaveType, $payload['policy_id'] ?? null);
            $hoursPerDay = $policy !== null ? (float) $policy->hours_per_workday : 8.0;
            $durationUnit = (string) ($payload['duration_unit'] ?? ($policy !== null ? $policy->unit_mode : $leaveType->defaultUnitMode()->value));
            $halfOrHourly = isset($payload['half_day_or_hourly_duration']) ? (string) $payload['half_day_or_hourly_duration'] : null;

            [$requestedDays, $requestedHours] = $this->resolveDurations(
                startDate: $startDate,
                endDate: $endDate,
                hoursPerDay: $hoursPerDay,
                halfDayOrHourly: $halfOrHourly,
                explicitDays: isset($payload['requested_days']) ? (float) $payload['requested_days'] : null,
                explicitHours: isset($payload['requested_hours']) ? (float) $payload['requested_hours'] : null,
            );

            if ($leaveType === LeaveTypeCode::IT_Legge104_Permessi) {
                $this->assertLegge104MonthlyLimit($tenantId, (string) $employee->id, $startDate, $requestedHours, $policy);
            }

            $staffingCheck = $this->staffingService->checkThreshold($tenantId, $employee->department_id, $startDate, $endDate);
            if ($staffingCheck->breached && $staffingCheck->enforceHardBlock && $leaveType->consumesBalance()) {
                $firstDay = $staffingCheck->criticalDays[0];

                throw LeaveManagementException::staffingThresholdBreached(
                    $firstDay['date'],
                    $firstDay['present_count'],
                    $firstDay['required_min']
                );
            }

            $ledger = null;
            if ($policy !== null && $leaveType->consumesBalance()) {
                $ledger = $this->accrualService->findOrCreateLedgerLocked($tenantId, $employee, $policy, $startDate->year);
                $consumeAmount = $durationUnit === LeaveUnitMode::HOURS->value ? $requestedHours : $requestedDays;
                $available = (float) $this->accrualService->availableBalance($ledger);

                if ($available < $consumeAmount) {
                    throw LeaveManagementException::insufficientBalance($available, $consumeAmount);
                }

                $ledger->pending_approval_amount = LeaveDecimal::round2(
                    LeaveDecimal::add((string) $ledger->pending_approval_amount, (string) $consumeAmount)
                );
                $this->accrualService->refreshDeadlinesAndProjection($ledger, $policy, CarbonImmutable::now()->startOfDay());
                $ledger->save();
            }

            $indemnity = $this->indemnityCalculator->calculate($country, $leaveType, $startDate, $endDate, $policy);
            $requestNumber = 'LM-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));

            $leaveRequest = LeaveRequest::query()->create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'employee_id' => $employee->id,
                'department_id' => $employee->department_id,
                'policy_id' => $policy?->id,
                'ledger_id' => $ledger?->id,
                'request_number' => $requestNumber,
                'country' => $country->value,
                'leave_type' => $leaveType->value,
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'half_day_or_hourly_duration' => $halfOrHourly,
                'duration_unit' => $durationUnit,
                'requested_days' => LeaveDecimal::round2((string) $requestedDays),
                'requested_hours' => LeaveDecimal::round2((string) $requestedHours),
                'status' => LeaveRequestStatus::PENDING_APPROVAL->value,
                'legge_104_beneficiary_ref' => $payload['legge_104_beneficiary_ref'] ?? null,
                'indemnity_breakdown' => $indemnity->toArray(),
                'staffing_check_result' => $staffingCheck->toArray(),
                'notes' => $payload['notes'] ?? null,
                'metadata' => isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : null,
            ]);

            $this->auditLedger->record('LM_LEAVE_REQUEST_SUBMITTED', $leaveRequest, null, $leaveRequest->auditPayload($leaveRequest->attributesToArray()), 'MODULE_76_LEAVE');

            $this->dispatcher->publishAndDispatch(new LeaveAbsenceSubmittedEvent(
                leaveRequestId: (string) $leaveRequest->id,
                requestNumber: $requestNumber,
                employeeId: (string) $employee->id,
                departmentId: $employee->department_id,
                country: $country->value,
                leaveType: $leaveType->value,
                startDate: $startDate->format('Y-m-d'),
                endDate: $endDate->format('Y-m-d'),
                requestedDays: $requestedDays,
                requestedHours: $requestedHours,
                staffingThresholdBreached: $staffingCheck->breached,
                tenantId: $tenantId,
            ));

            if ($policy !== null && ! $policy->requires_approval) {
                return $this->approve($leaveRequest, $actorUserId);
            }

            $this->startApprovalWorkflowIfConfigured($leaveRequest, $tenantId, $actorUserId);

            return $leaveRequest->fresh() ?? $leaveRequest;
        });
    }

    /**
     * Register a sick leave (with 9-char INPS PUC or CH IJM/KTG Wartefrist), accident (SUVA / INAIL),
     * or IPG/EO service absence and calculate statutory indemnity brackets.
     *
     * @param  array<string, mixed>  $payload
     */
    public function registerSickOrAccidentLeave(string $tenantId, array $payload, ?string $actorUserId = null): LeaveRequest
    {
        return DB::transaction(function () use ($tenantId, $payload, $actorUserId): LeaveRequest {
            $employee = Employee::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail((string) $payload['employee_id']);

            $country = LeaveCountry::from(strtoupper((string) ($payload['country'] ?? $this->accrualService->resolveEmployeeCountry($employee))));
            $leaveType = LeaveTypeCode::from((string) ($payload['leave_type'] ?? LeaveTypeCode::SickLeave_Malattia->value));
            $startDate = CarbonImmutable::parse((string) $payload['start_date'])->startOfDay();
            $endDate = CarbonImmutable::parse((string) $payload['end_date'])->startOfDay();

            if ($endDate->lt($startDate)) {
                throw LeaveManagementException::validation('end_date_before_start');
            }

            $this->assertNoOverlappingAbsence($tenantId, (string) $employee->id, $startDate, $endDate);

            $puc = InpsProtocolNumber::tryFromString(isset($payload['inps_medical_certificate_protocol_number']) ? (string) $payload['inps_medical_certificate_protocol_number'] : null);
            if ($country === LeaveCountry::IT && $leaveType === LeaveTypeCode::SickLeave_Malattia && $puc === null) {
                throw LeaveManagementException::validation('inps_puc_required');
            }

            $policy = $this->resolvePolicy($tenantId, $country, $leaveType, $payload['policy_id'] ?? null);
            $hoursPerDay = $policy !== null ? (float) $policy->hours_per_workday : 8.0;
            $halfOrHourly = isset($payload['half_day_or_hourly_duration']) ? (string) $payload['half_day_or_hourly_duration'] : null;

            [$requestedDays, $requestedHours] = $this->resolveDurations(
                startDate: $startDate,
                endDate: $endDate,
                hoursPerDay: $hoursPerDay,
                halfDayOrHourly: $halfOrHourly,
                explicitDays: isset($payload['requested_days']) ? (float) $payload['requested_days'] : null,
                explicitHours: isset($payload['requested_hours']) ? (float) $payload['requested_hours'] : null,
            );

            $ijmWaitingDays = isset($payload['ijm_ktg_waiting_days']) ? (int) $payload['ijm_ktg_waiting_days'] : null;
            $isOccupational = isset($payload['is_accident_occupational']) ? (bool) $payload['is_accident_occupational'] : null;

            $indemnity = $this->indemnityCalculator->calculate(
                country: $country,
                leaveType: $leaveType,
                startDate: $startDate,
                endDate: $endDate,
                policy: $policy,
                ijmKtgWaitingDaysOverride: $ijmWaitingDays,
                isOccupationalAccident: $isOccupational,
            );

            $staffingCheck = $this->staffingService->checkThreshold($tenantId, $employee->department_id, $startDate, $endDate);
            $requestNumber = 'MED-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));

            $autoApprove = (bool) ($payload['auto_approve'] ?? true);
            $approverId = $autoApprove && $actorUserId !== null && Str::isUuid($actorUserId) ? $actorUserId : null;

            $leaveRequest = LeaveRequest::query()->create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'employee_id' => $employee->id,
                'department_id' => $employee->department_id,
                'policy_id' => $policy?->id,
                'request_number' => $requestNumber,
                'country' => $country->value,
                'leave_type' => $leaveType->value,
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'half_day_or_hourly_duration' => $halfOrHourly,
                'duration_unit' => LeaveUnitMode::DAYS->value,
                'requested_days' => LeaveDecimal::round2((string) $requestedDays),
                'requested_hours' => LeaveDecimal::round2((string) $requestedHours),
                'status' => $autoApprove ? LeaveRequestStatus::APPROVED->value : LeaveRequestStatus::PENDING_APPROVAL->value,
                'inps_medical_certificate_protocol_number' => $puc?->value,
                'suva_claim_number' => $payload['suva_claim_number'] ?? null,
                'inail_claim_number' => $payload['inail_claim_number'] ?? null,
                'medical_certificate_doc_id' => $payload['medical_certificate_doc_id'] ?? null,
                'certificate_issue_date' => $payload['certificate_issue_date'] ?? null,
                'is_accident_occupational' => $isOccupational,
                'is_fault_absence' => (bool) ($payload['is_fault_absence'] ?? false),
                'is_pregnancy_related' => (bool) ($payload['is_pregnancy_related'] ?? false),
                'ijm_ktg_waiting_days' => $ijmWaitingDays,
                'ipg_eo_allowance_code' => $payload['ipg_eo_allowance_code'] ?? null,
                'indemnity_breakdown' => $indemnity->toArray(),
                'staffing_check_result' => $staffingCheck->toArray(),
                'approved_by_user_id' => $approverId,
                'approved_at' => $autoApprove ? now() : null,
                'notes' => $payload['notes'] ?? null,
            ]);

            $this->auditLedger->record('LM_SICK_OR_ACCIDENT_REGISTERED', $leaveRequest, null, $leaveRequest->auditPayload($leaveRequest->attributesToArray()), 'MODULE_76_LEAVE');

            $this->dispatcher->publishAndDispatch(new SickLeaveInpsRegisteredEvent(
                leaveRequestId: (string) $leaveRequest->id,
                employeeId: (string) $employee->id,
                country: $country->value,
                leaveType: $leaveType->value,
                startDate: $startDate->format('Y-m-d'),
                endDate: $endDate->format('Y-m-d'),
                indemnityBreakdown: $indemnity->toArray(),
                hasInpsProtocol: $puc !== null,
                hasSuvaOrInailClaim: ! empty($leaveRequest->suva_claim_number) || ! empty($leaveRequest->inail_claim_number),
                tenantId: $tenantId,
            ));

            if ($autoApprove) {
                $this->dispatcher->publishAndDispatch(new LeaveAbsenceApprovedEvent(
                    leaveRequestId: (string) $leaveRequest->id,
                    requestNumber: $requestNumber,
                    employeeId: (string) $employee->id,
                    departmentId: $employee->department_id,
                    country: $country->value,
                    leaveType: $leaveType->value,
                    startDate: $startDate->format('Y-m-d'),
                    endDate: $endDate->format('Y-m-d'),
                    requestedDays: $requestedDays,
                    requestedHours: $requestedHours,
                    approvedByUserId: $approverId,
                    tenantId: $tenantId,
                ));
            }

            return $leaveRequest->fresh() ?? $leaveRequest;
        });
    }

    public function approve(LeaveRequest $leaveRequest, ?string $approverUserId = null): LeaveRequest
    {
        return DB::transaction(function () use ($leaveRequest, $approverUserId): LeaveRequest {
            $locked = LeaveRequest::query()->whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === LeaveRequestStatus::APPROVED->value) {
                return $locked;
            }

            if ($locked->status !== LeaveRequestStatus::PENDING_APPROVAL->value) {
                throw LeaveManagementException::invalidStateTransition($locked->status, 'approve');
            }

            if ($locked->isLockedByWorkflow()) {
                throw LeaveManagementException::conflict('locked_by_workflow');
            }

            $before = $locked->auditPayload($locked->attributesToArray());
            $this->movePendingToTakenOrPlanned($locked);

            $approverId = $approverUserId !== null && Str::isUuid($approverUserId) ? $approverUserId : null;
            $locked->update([
                'status' => LeaveRequestStatus::APPROVED->value,
                'is_locked' => false,
                'approved_by_user_id' => $approverId,
                'approved_at' => now(),
            ]);

            $this->auditLedger->record('LM_LEAVE_REQUEST_APPROVED', $locked, $before, $locked->auditPayload($locked->attributesToArray()), 'MODULE_76_LEAVE');

            $this->dispatcher->publishAndDispatch(new LeaveAbsenceApprovedEvent(
                leaveRequestId: (string) $locked->id,
                requestNumber: $locked->request_number,
                employeeId: $locked->employee_id,
                departmentId: $locked->department_id,
                country: $locked->country,
                leaveType: $locked->leave_type,
                startDate: $locked->start_date->format('Y-m-d'),
                endDate: $locked->end_date->format('Y-m-d'),
                requestedDays: (float) $locked->requested_days,
                requestedHours: (float) $locked->requested_hours,
                approvedByUserId: $approverId,
                tenantId: (string) $locked->tenant_id,
            ));

            return $locked->fresh() ?? $locked;
        });
    }

    public function reject(LeaveRequest $leaveRequest, string $reason, ?string $actorUserId = null): LeaveRequest
    {
        return DB::transaction(function () use ($leaveRequest, $reason, $actorUserId): LeaveRequest {
            $locked = LeaveRequest::query()->whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === LeaveRequestStatus::REJECTED->value) {
                return $locked;
            }

            if ($locked->status !== LeaveRequestStatus::PENDING_APPROVAL->value) {
                throw LeaveManagementException::invalidStateTransition($locked->status, 'reject');
            }

            if ($locked->isLockedByWorkflow()) {
                throw LeaveManagementException::conflict('locked_by_workflow');
            }

            $before = $locked->auditPayload($locked->attributesToArray());
            $this->releaseLedgerBucket($locked, 'pending_approval_amount');

            $locked->update([
                'status' => LeaveRequestStatus::REJECTED->value,
                'is_locked' => false,
                'rejection_reason' => $reason,
            ]);

            $this->auditLedger->record('LM_LEAVE_REQUEST_REJECTED', $locked, $before, $locked->auditPayload($locked->attributesToArray()), 'MODULE_76_LEAVE');

            $this->dispatcher->publishAndDispatch(new LeaveAbsenceRejectedEvent(
                leaveRequestId: (string) $locked->id,
                requestNumber: $locked->request_number,
                employeeId: $locked->employee_id,
                leaveType: $locked->leave_type,
                rejectedByUserId: $actorUserId !== null && Str::isUuid($actorUserId) ? $actorUserId : null,
                tenantId: (string) $locked->tenant_id,
            ));

            return $locked->fresh() ?? $locked;
        });
    }

    public function cancel(LeaveRequest $leaveRequest, string $reason, ?string $actorUserId = null): LeaveRequest
    {
        return DB::transaction(function () use ($leaveRequest, $reason, $actorUserId): LeaveRequest {
            $locked = LeaveRequest::query()->whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();
            $before = $locked->auditPayload($locked->attributesToArray());

            if ($locked->status === LeaveRequestStatus::PENDING_APPROVAL->value) {
                if ($locked->workflow_instance_id !== null && $this->workflowEngine !== null) {
                    $instance = WorkflowInstance::query()->find($locked->workflow_instance_id);
                    if ($instance !== null && in_array($instance->status, ['pending', 'in_progress'], true)) {
                        $this->workflowEngine->cancel($instance, $reason);
                    }
                }
                $this->releaseLedgerBucket($locked, 'pending_approval_amount');
            } elseif ($locked->status === LeaveRequestStatus::APPROVED->value && $locked->start_date->isAfter(now()->startOfDay())) {
                $bucket = $locked->start_date->isFuture() ? 'planned_approved_future' : 'taken_ytd';
                $this->releaseLedgerBucket($locked, $bucket);
            } else {
                throw LeaveManagementException::invalidStateTransition($locked->status, 'cancel');
            }

            $locked->update([
                'status' => LeaveRequestStatus::CANCELLED->value,
                'is_locked' => false,
                'rejection_reason' => $reason,
            ]);

            $this->auditLedger->record('LM_LEAVE_REQUEST_CANCELLED', $locked, $before, $locked->auditPayload($locked->attributesToArray()), 'MODULE_76_LEAVE');

            $this->dispatcher->publishAndDispatch(new LeaveAbsenceCancelledEvent(
                leaveRequestId: (string) $locked->id,
                requestNumber: $locked->request_number,
                employeeId: $locked->employee_id,
                leaveType: $locked->leave_type,
                cancelledByUserId: $actorUserId !== null && Str::isUuid($actorUserId) ? $actorUserId : null,
                tenantId: (string) $locked->tenant_id,
            ));

            return $locked->fresh() ?? $locked;
        });
    }

    private function assertNoOverlappingAbsence(string $tenantId, string $employeeId, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $exists = LeaveRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->whereIn('status', [LeaveRequestStatus::PENDING_APPROVAL->value, LeaveRequestStatus::APPROVED->value])
            ->whereDate('start_date', '<=', $end->format('Y-m-d'))
            ->whereDate('end_date', '>=', $start->format('Y-m-d'))
            ->exists();

        if ($exists) {
            throw LeaveManagementException::overlapDetected($start->format('Y-m-d'), $end->format('Y-m-d'));
        }
    }

    private function assertLegge104MonthlyLimit(
        string $tenantId,
        string $employeeId,
        CarbonImmutable $startDate,
        float $requestedHours,
        ?LeaveEntitlementPolicy $policy,
    ): void {
        $maxDays = $policy?->legge_104_monthly_days_limit !== null ? (float) $policy->legge_104_monthly_days_limit : 3.0;
        $hoursPerDay = $policy !== null ? (float) $policy->hours_per_workday : 8.0;
        $maxHours = $maxDays * ($hoursPerDay > 0 ? $hoursPerDay : 8.0);

        $monthStart = $startDate->startOfMonth()->format('Y-m-d');
        $monthEnd = $startDate->endOfMonth()->format('Y-m-d');

        $alreadyUsedHours = (float) LeaveRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('leave_type', LeaveTypeCode::IT_Legge104_Permessi->value)
            ->whereIn('status', [LeaveRequestStatus::PENDING_APPROVAL->value, LeaveRequestStatus::APPROVED->value])
            ->whereDate('start_date', '>=', $monthStart)
            ->whereDate('start_date', '<=', $monthEnd)
            ->sum('requested_hours');

        if (($alreadyUsedHours + $requestedHours) > ($maxHours + 0.01)) {
            throw LeaveManagementException::legge104LimitExceeded($alreadyUsedHours, $requestedHours, $maxHours);
        }
    }

    private function resolvePolicy(
        string $tenantId,
        LeaveCountry $country,
        LeaveTypeCode $leaveType,
        mixed $policyId,
    ): ?LeaveEntitlementPolicy {
        if (is_string($policyId) && $policyId !== '') {
            return LeaveEntitlementPolicy::query()->where('tenant_id', $tenantId)->find($policyId);
        }

        return LeaveEntitlementPolicy::query()
            ->where('tenant_id', $tenantId)
            ->where('country', $country->value)
            ->where('leave_type', $leaveType->value)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @return array{0: float, 1: float} [days, hours]
     */
    private function resolveDurations(
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        float $hoursPerDay,
        ?string $halfDayOrHourly,
        ?float $explicitDays,
        ?float $explicitHours,
    ): array {
        if ($halfDayOrHourly !== null && str_starts_with($halfDayOrHourly, 'hourly:')) {
            $hours = max(0.25, (float) substr($halfDayOrHourly, 7));

            return [round($hours / max(1.0, $hoursPerDay), 2), round($hours, 2)];
        }

        if ($halfDayOrHourly === 'morning' || $halfDayOrHourly === 'afternoon' || $halfDayOrHourly === 'half_day') {
            return [0.5, round($hoursPerDay / 2.0, 2)];
        }

        if ($explicitHours !== null && $explicitHours > 0) {
            return [$explicitDays ?? round($explicitHours / max(1.0, $hoursPerDay), 2), round($explicitHours, 2)];
        }

        if ($explicitDays !== null && $explicitDays > 0) {
            return [round($explicitDays, 2), round($explicitDays * $hoursPerDay, 2)];
        }

        $calendarDays = max(1, (int) $startDate->diffInDays($endDate) + 1);
        $workingDays = 0;
        $cursor = $startDate;
        while ($cursor->lte($endDate)) {
            if ($cursor->dayOfWeekIso <= 5) {
                $workingDays++;
            }
            $cursor = $cursor->addDay();
        }
        $days = (float) ($workingDays > 0 ? $workingDays : $calendarDays);

        return [round($days, 2), round($days * $hoursPerDay, 2)];
    }

    private function movePendingToTakenOrPlanned(LeaveRequest $request): void
    {
        if ($request->ledger_id === null) {
            return;
        }

        $ledger = EmployeeLeaveAccrualLedger::query()->whereKey($request->ledger_id)->lockForUpdate()->first();
        if ($ledger === null) {
            return;
        }

        $amount = $request->duration_unit === LeaveUnitMode::HOURS->value
            ? (string) $request->requested_hours
            : (string) $request->requested_days;

        $ledger->pending_approval_amount = LeaveDecimal::round2(
            LeaveDecimal::max('0', LeaveDecimal::sub((string) $ledger->pending_approval_amount, $amount))
        );

        if ($request->start_date->isAfter(CarbonImmutable::now()->startOfDay())) {
            $ledger->planned_approved_future = LeaveDecimal::round2(
                LeaveDecimal::add((string) $ledger->planned_approved_future, $amount)
            );
        } else {
            $ledger->taken_ytd = LeaveDecimal::round2(
                LeaveDecimal::add((string) $ledger->taken_ytd, $amount)
            );
        }

        $this->accrualService->refreshDeadlinesAndProjection($ledger, $ledger->policy, CarbonImmutable::now()->startOfDay());
        $ledger->save();
    }

    private function releaseLedgerBucket(LeaveRequest $request, string $bucket): void
    {
        if ($request->ledger_id === null) {
            return;
        }

        $ledger = EmployeeLeaveAccrualLedger::query()->whereKey($request->ledger_id)->lockForUpdate()->first();
        if ($ledger === null) {
            return;
        }

        $amount = $request->duration_unit === LeaveUnitMode::HOURS->value
            ? (string) $request->requested_hours
            : (string) $request->requested_days;

        $current = (string) $ledger->{$bucket};
        $ledger->{$bucket} = LeaveDecimal::round2(LeaveDecimal::max('0', LeaveDecimal::sub($current, $amount)));

        if ($bucket === 'planned_approved_future' && LeaveDecimal::compare($current, $amount) < 0) {
            $remainder = LeaveDecimal::sub($amount, $current);
            $ledger->taken_ytd = LeaveDecimal::round2(LeaveDecimal::max('0', LeaveDecimal::sub((string) $ledger->taken_ytd, $remainder)));
        }

        $this->accrualService->refreshDeadlinesAndProjection($ledger, $ledger->policy, CarbonImmutable::now()->startOfDay());
        $ledger->save();
    }

    private function startApprovalWorkflowIfConfigured(LeaveRequest $leaveRequest, string $tenantId, ?string $actorUserId): void
    {
        if ($this->workflowEngine === null) {
            return;
        }

        $hasActive = WorkflowDefinition::query()
            ->where('tenant_id', $tenantId)
            ->where('code', self::WORKFLOW_CODE)
            ->where('is_active', true)
            ->exists();

        if (! $hasActive) {
            return;
        }

        $instance = $this->workflowEngine->start(new StartWorkflowDTO(
            workflowCode: self::WORKFLOW_CODE,
            approvable: $leaveRequest,
            contextSnapshot: [
                'request_number' => $leaveRequest->request_number,
                'leave_type' => $leaveRequest->leave_type,
                'start_date' => $leaveRequest->start_date->format('Y-m-d'),
                'end_date' => $leaveRequest->end_date->format('Y-m-d'),
                'requested_days' => (float) $leaveRequest->requested_days,
                'requested_hours' => (float) $leaveRequest->requested_hours,
            ],
            tenantId: $tenantId,
            companyId: $tenantId,
            initiatorUserId: $actorUserId !== null && Str::isUuid($actorUserId) ? $actorUserId : null,
        ));

        $leaveRequest->refresh();
        if ($leaveRequest->status === LeaveRequestStatus::PENDING_APPROVAL->value && ! in_array($instance->status, ['approved', 'completed', 'rejected', 'cancelled'], true)) {
            $leaveRequest->lockByWorkflow($instance);
        }
    }
}
