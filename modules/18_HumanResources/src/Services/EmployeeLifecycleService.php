<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\HumanResources\Contracts\EmployeeLifecycleServiceInterface;
use Modules\HumanResources\DTOs\HireEmployeeDTO;
use Modules\HumanResources\DTOs\PromoteEmployeeDTO;
use Modules\HumanResources\DTOs\TerminateEmployeeDTO;
use Modules\HumanResources\DTOs\TransferEmployeeDTO;
use Modules\HumanResources\Events\EmployeeCostForecastEvent;
use Modules\HumanResources\Events\EmployeeCostReadyEvent;
use Modules\HumanResources\Events\EmployeeHiredEvent;
use Modules\HumanResources\Events\EmployeePromotedEvent;
use Modules\HumanResources\Events\EmployeeTerminatedEvent;
use Modules\HumanResources\Events\EmployeeTransferredEvent;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\EmployeeLifecycleEvent;
use Modules\HumanResources\Models\EmploymentContract;
use Modules\HumanResources\Models\Position;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Models\WorkflowDefinition;

final readonly class EmployeeLifecycleService implements EmployeeLifecycleServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
        private ?WorkflowEngineInterface $workflowEngine = null
    ) {}

    public function hireEmployee(HireEmployeeDTO $dto, ?string $actorUserId = null): Employee
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $empNumber = $dto->employeeNumber ?: 'EMP-'.date('Ymd').'-'.strtoupper(Str::random(5));
            $hireDate = $dto->hireDate ?: date('Y-m-d');
            $probationEnd = $dto->probationMonths > 0
                ? date('Y-m-d', strtotime("+{$dto->probationMonths} months", strtotime($hireDate)))
                : null;

            $annualHours = max(1.0, $dto->weeklyHours * 52.0);
            $baseHourlyRate = round($dto->baseAnnualSalary / $annualHours, 2);
            $loadedHourlyCost = round($baseHourlyRate * 1.35, 2); // 35% statutory employer social contributions

            $fullName = trim($dto->firstName.' '.$dto->lastName);

            $employee = Employee::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId,
                'user_id' => $dto->userId,
                'employee_number' => $empNumber,
                'first_name' => $dto->firstName,
                'last_name' => $dto->lastName,
                'full_name' => $fullName,
                'work_email' => $dto->workEmail,
                'nationality' => $dto->nationality,
                'tax_code' => $dto->taxCode,
                'department_id' => $dto->departmentId,
                'team_id' => $dto->teamId,
                'business_unit_id' => $dto->businessUnitId,
                'cost_center_id' => $dto->costCenterId,
                'position_id' => $dto->positionId,
                'job_grade_id' => $dto->jobGradeId,
                'manager_id' => $dto->managerId,
                'employment_status' => $dto->probationMonths > 0 ? 'probation' : 'onboarding',
                'employment_type' => $dto->employmentType,
                'hire_date' => $hireDate,
                'probation_end_date' => $probationEnd,
                'base_annual_salary' => $dto->baseAnnualSalary,
                'hourly_cost_rate' => $loadedHourlyCost,
                'currency' => $dto->currency,
                'fte_ratio' => round($dto->weeklyHours / 40.0, 2),
                'approval_status' => 'approved',
                'is_locked' => false,
                'emergency_contact' => $dto->emergencyContact,
                'equipment_assigned' => [],
                'metadata' => $dto->metadata,
            ]);

            $jobTitle = 'Specialist';
            if ($dto->positionId !== null) {
                $pos = Position::find($dto->positionId);
                if ($pos !== null) {
                    $jobTitle = (string) $pos->title;
                }
            }

            EmploymentContract::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId,
                'employee_id' => $employee->id,
                'contract_number' => 'CTR-'.$empNumber,
                'contract_type' => $dto->contractType,
                'collective_agreement' => $dto->collectiveAgreement,
                'job_title' => $jobTitle,
                'job_grade_id' => $dto->jobGradeId,
                'start_date' => $hireDate,
                'probation_end_date' => $probationEnd,
                'weekly_hours' => $dto->weeklyHours,
                'fte_percentage' => round(($dto->weeklyHours / 40.0) * 100.0, 2),
                'base_annual_salary' => $dto->baseAnnualSalary,
                'hourly_rate' => $baseHourlyRate,
                'loaded_hourly_cost' => $loadedHourlyCost,
                'currency' => $dto->currency,
                'status' => 'active',
                'is_active' => true,
            ]);

            $actorUuid = ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : null;

            EmployeeLifecycleEvent::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId,
                'employee_id' => $employee->id,
                'event_type' => 'hiring',
                'effective_date' => $hireDate,
                'from_status' => 'candidate',
                'to_status' => $employee->employment_status,
                'to_department_id' => $dto->departmentId,
                'to_position_id' => $dto->positionId,
                'to_job_grade_id' => $dto->jobGradeId,
                'from_salary' => 0.00,
                'to_salary' => $dto->baseAnnualSalary,
                'reason' => 'Initial Hiring & Onboarding',
                'initiated_by_user_id' => $actorUuid,
            ]);

            $this->tryStartWorkflowIfDefined(
                workflowCode: 'HR_ONBOARDING_APPROVAL',
                approvable: $employee,
                context: [
                    'employee_number' => $employee->employee_number,
                    'base_annual_salary' => $dto->baseAnnualSalary,
                ],
                tenantId: $dto->tenantId,
                actorUserId: $actorUserId
            );

            $this->auditLedger->record(
                action: 'HR_EMPLOYEE_HIRED',
                model: $employee,
                payloadBefore: null,
                payloadAfter: $employee->fresh()->toArray(),
                moduleCode: 'MODULE_18_HR'
            );

            $hiredEvent = new EmployeeHiredEvent(
                employeeId: (string) $employee->id,
                employeeNumber: (string) $employee->employee_number,
                fullName: $fullName,
                departmentId: $dto->departmentId,
                positionId: $dto->positionId,
                baseAnnualSalary: $dto->baseAnnualSalary,
                hireDate: $hireDate,
                tenantId: $dto->tenantId,
                companyId: $dto->companyId
            );
            $this->dispatcher->publish($hiredEvent);
            event($hiredEvent);

            $monthlyGross = round($dto->baseAnnualSalary / 12.0, 2);
            $monthlyContrib = round($monthlyGross * 0.35, 2);
            $totalMonthly = round($monthlyGross + $monthlyContrib, 2);

            $costReadyEvent = new EmployeeCostReadyEvent(
                employeeId: (string) $employee->id,
                costCenterId: $dto->costCenterId,
                monthlyGrossCost: $monthlyGross,
                monthlyEmployerContributions: $monthlyContrib,
                totalMonthlyCost: $totalMonthly,
                currency: $dto->currency,
                periodCode: date('Y-m', strtotime($hireDate)),
                tenantId: $dto->tenantId,
                companyId: $dto->companyId
            );
            $this->dispatcher->publish($costReadyEvent);
            event($costReadyEvent);

            $forecastEvent = new EmployeeCostForecastEvent(
                employeeId: (string) $employee->id,
                annualTotalCost: round($totalMonthly * 12.0, 2),
                monthlyTotalCost: $totalMonthly,
                currency: $dto->currency,
                effectiveFrom: $hireDate,
                tenantId: $dto->tenantId,
                companyId: $dto->companyId
            );
            $this->dispatcher->publish($forecastEvent);
            event($forecastEvent);

            return $employee->fresh();
        });
    }

    public function completeProbation(Employee $employee, ?string $confirmationDate = null, ?string $actorUserId = null): Employee
    {
        return DB::transaction(function () use ($employee, $confirmationDate, $actorUserId) {
            $locked = Employee::where('id', $employee->id)->lockForUpdate()->firstOrFail();
            $before = $locked->toArray();
            $confDate = $confirmationDate ?: date('Y-m-d');
            $actorUuid = ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : null;

            $locked->update([
                'employment_status' => 'active',
                'confirmation_date' => $confDate,
            ]);

            EmployeeLifecycleEvent::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $locked->tenant_id,
                'company_id' => $locked->company_id ?? $locked->tenant_id,
                'employee_id' => $locked->id,
                'event_type' => 'probation_passed',
                'effective_date' => $confDate,
                'from_status' => $before['employment_status'],
                'to_status' => 'active',
                'from_salary' => (float) $locked->base_annual_salary,
                'to_salary' => (float) $locked->base_annual_salary,
                'reason' => 'Probation period successfully completed',
                'initiated_by_user_id' => $actorUuid,
            ]);

            $this->auditLedger->record(
                action: 'HR_PROBATION_COMPLETED',
                model: $locked,
                payloadBefore: $before,
                payloadAfter: $locked->fresh()->toArray(),
                moduleCode: 'MODULE_18_HR'
            );

            return $locked->fresh();
        });
    }

    public function promoteEmployee(PromoteEmployeeDTO $dto, ?string $actorUserId = null): EmployeeLifecycleEvent
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $locked = Employee::where('id', $dto->employeeId)->lockForUpdate()->firstOrFail();

            if (in_array($locked->employment_status, ['terminated', 'offboarding', 'retired'], true)) {
                throw new InvalidArgumentException("Cannot promote terminated or retired employee [{$locked->employee_number}].");
            }

            $effDate = $dto->effectiveDate ?: date('Y-m-d');

            // Concurrency / Idempotency protection: prevent duplicate promotion on the same date to the same salary/grade
            $duplicate = EmployeeLifecycleEvent::where('tenant_id', $locked->tenant_id)
                ->where('employee_id', $locked->id)
                ->where('event_type', 'promotion')
                ->whereDate('effective_date', $effDate)
                ->where('to_salary', $dto->newBaseAnnualSalary)
                ->exists();

            if ($duplicate) {
                throw new InvalidArgumentException("Duplicate promotion prevented for employee [{$locked->employee_number}] on {$effDate}.");
            }

            $before = $locked->toArray();
            $fromSalary = (float) $locked->base_annual_salary;
            $fromPosition = $locked->position_id;
            $fromGrade = $locked->job_grade_id;
            $actorUuid = ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : null;

            $newHourlyCost = round(($dto->newBaseAnnualSalary / 2080.0) * 1.35, 2);

            $lifecycleEvent = EmployeeLifecycleEvent::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $locked->tenant_id,
                'company_id' => $locked->company_id ?? $locked->tenant_id,
                'employee_id' => $locked->id,
                'event_type' => 'promotion',
                'effective_date' => $effDate,
                'from_status' => $locked->employment_status,
                'to_status' => 'promoted',
                'from_department_id' => $locked->department_id,
                'to_department_id' => $locked->department_id,
                'from_position_id' => $fromPosition,
                'to_position_id' => $dto->newPositionId ?? $fromPosition,
                'from_job_grade_id' => $fromGrade,
                'to_job_grade_id' => $dto->newJobGradeId ?? $fromGrade,
                'from_salary' => $fromSalary,
                'to_salary' => $dto->newBaseAnnualSalary,
                'reason' => $dto->reason,
                'initiated_by_user_id' => $actorUuid,
            ]);

            $this->tryStartWorkflowIfDefined(
                workflowCode: 'HR_PROMOTION_APPROVAL',
                approvable: $lifecycleEvent,
                context: [
                    'employee_number' => $locked->employee_number,
                    'from_salary' => $fromSalary,
                    'to_salary' => $dto->newBaseAnnualSalary,
                ],
                tenantId: (string) $locked->tenant_id,
                actorUserId: $actorUserId
            );

            $locked->update([
                'employment_status' => 'promoted',
                'position_id' => $dto->newPositionId ?? $fromPosition,
                'job_grade_id' => $dto->newJobGradeId ?? $fromGrade,
                'base_annual_salary' => $dto->newBaseAnnualSalary,
                'hourly_cost_rate' => $newHourlyCost,
            ]);

            EmploymentContract::where('employee_id', $locked->id)
                ->where('is_active', true)
                ->update([
                    'job_grade_id' => $dto->newJobGradeId ?? $fromGrade,
                    'base_annual_salary' => $dto->newBaseAnnualSalary,
                    'hourly_rate' => round($dto->newBaseAnnualSalary / 2080.0, 2),
                    'loaded_hourly_cost' => $newHourlyCost,
                ]);

            $this->auditLedger->record(
                action: 'HR_EMPLOYEE_PROMOTED',
                model: $locked,
                payloadBefore: $before,
                payloadAfter: $locked->fresh()->toArray(),
                moduleCode: 'MODULE_18_HR'
            );

            $promotedEvent = new EmployeePromotedEvent(
                lifecycleEventId: (string) $lifecycleEvent->id,
                employeeId: (string) $locked->id,
                fromPositionId: $fromPosition ? (string) $fromPosition : null,
                toPositionId: $dto->newPositionId ?? ($fromPosition ? (string) $fromPosition : null),
                fromJobGradeId: $fromGrade ? (string) $fromGrade : null,
                toJobGradeId: $dto->newJobGradeId ?? ($fromGrade ? (string) $fromGrade : null),
                fromSalary: $fromSalary,
                toSalary: $dto->newBaseAnnualSalary,
                effectiveDate: $effDate,
                tenantId: (string) $locked->tenant_id,
                companyId: (string) ($locked->company_id ?? $locked->tenant_id)
            );
            $this->dispatcher->publish($promotedEvent);
            event($promotedEvent);

            $monthlyTotal = round(($dto->newBaseAnnualSalary / 12.0) * 1.35, 2);
            $forecastEvent = new EmployeeCostForecastEvent(
                employeeId: (string) $locked->id,
                annualTotalCost: round($monthlyTotal * 12.0, 2),
                monthlyTotalCost: $monthlyTotal,
                currency: (string) $locked->currency,
                effectiveFrom: $effDate,
                tenantId: (string) $locked->tenant_id,
                companyId: (string) ($locked->company_id ?? $locked->tenant_id)
            );
            $this->dispatcher->publish($forecastEvent);
            event($forecastEvent);

            return $lifecycleEvent->fresh();
        });
    }

    public function transferEmployee(TransferEmployeeDTO $dto, ?string $actorUserId = null): EmployeeLifecycleEvent
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $locked = Employee::where('id', $dto->employeeId)->lockForUpdate()->firstOrFail();

            if (in_array($locked->employment_status, ['terminated', 'offboarding', 'retired'], true)) {
                throw new InvalidArgumentException("Cannot transfer terminated employee [{$locked->employee_number}].");
            }

            $before = $locked->toArray();
            $effDate = $dto->effectiveDate ?: date('Y-m-d');
            $actorUuid = ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : null;

            $fromDept = $locked->department_id;
            $fromPos = $locked->position_id;

            $lifecycleEvent = EmployeeLifecycleEvent::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $locked->tenant_id,
                'company_id' => $locked->company_id ?? $locked->tenant_id,
                'employee_id' => $locked->id,
                'event_type' => 'transfer',
                'effective_date' => $effDate,
                'from_status' => $locked->employment_status,
                'to_status' => 'transferred',
                'from_department_id' => $fromDept,
                'to_department_id' => $dto->toDepartmentId ?? $fromDept,
                'from_position_id' => $fromPos,
                'to_position_id' => $dto->toPositionId ?? $fromPos,
                'from_salary' => (float) $locked->base_annual_salary,
                'to_salary' => (float) $locked->base_annual_salary,
                'reason' => $dto->reason,
                'initiated_by_user_id' => $actorUuid,
            ]);

            $this->tryStartWorkflowIfDefined(
                workflowCode: 'HR_TRANSFER_APPROVAL',
                approvable: $lifecycleEvent,
                context: [
                    'employee_number' => $locked->employee_number,
                    'to_department_id' => $dto->toDepartmentId,
                ],
                tenantId: (string) $locked->tenant_id,
                actorUserId: $actorUserId
            );

            $locked->update([
                'employment_status' => 'transferred',
                'department_id' => $dto->toDepartmentId ?? $locked->department_id,
                'team_id' => $dto->toTeamId ?? $locked->team_id,
                'business_unit_id' => $dto->toBusinessUnitId ?? $locked->business_unit_id,
                'cost_center_id' => $dto->toCostCenterId ?? $locked->cost_center_id,
                'position_id' => $dto->toPositionId ?? $locked->position_id,
                'manager_id' => $dto->toManagerId ?? $locked->manager_id,
            ]);

            $this->auditLedger->record(
                action: 'HR_EMPLOYEE_TRANSFERRED',
                model: $locked,
                payloadBefore: $before,
                payloadAfter: $locked->fresh()->toArray(),
                moduleCode: 'MODULE_18_HR'
            );

            $transferredEvent = new EmployeeTransferredEvent(
                lifecycleEventId: (string) $lifecycleEvent->id,
                employeeId: (string) $locked->id,
                fromDepartmentId: $fromDept ? (string) $fromDept : null,
                toDepartmentId: $dto->toDepartmentId ?? ($fromDept ? (string) $fromDept : null),
                fromPositionId: $fromPos ? (string) $fromPos : null,
                toPositionId: $dto->toPositionId ?? ($fromPos ? (string) $fromPos : null),
                effectiveDate: $effDate,
                tenantId: (string) $locked->tenant_id,
                companyId: (string) ($locked->company_id ?? $locked->tenant_id)
            );
            $this->dispatcher->publish($transferredEvent);
            event($transferredEvent);

            return $lifecycleEvent->fresh();
        });
    }

    public function terminateEmployee(TerminateEmployeeDTO $dto, ?string $actorUserId = null): Employee
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $locked = Employee::where('id', $dto->employeeId)->lockForUpdate()->firstOrFail();

            if ($locked->employment_status === 'terminated') {
                throw new InvalidArgumentException("Employee [{$locked->employee_number}] is already terminated.");
            }

            $before = $locked->toArray();
            $actorUuid = ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : null;

            $locked->update([
                'employment_status' => 'terminated',
                'termination_date' => $dto->terminationDate,
                'termination_reason' => $dto->reason,
            ]);

            EmploymentContract::where('employee_id', $locked->id)
                ->where('is_active', true)
                ->update([
                    'status' => 'terminated',
                    'is_active' => false,
                    'end_date' => $dto->terminationDate,
                ]);

            $lifecycleEvent = EmployeeLifecycleEvent::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $locked->tenant_id,
                'company_id' => $locked->company_id ?? $locked->tenant_id,
                'employee_id' => $locked->id,
                'event_type' => $dto->startOffboarding ? 'offboarding' : 'termination',
                'effective_date' => $dto->terminationDate,
                'from_status' => $before['employment_status'],
                'to_status' => 'terminated',
                'from_salary' => (float) $locked->base_annual_salary,
                'to_salary' => 0.00,
                'reason' => $dto->reason,
                'initiated_by_user_id' => $actorUuid,
            ]);

            $this->tryStartWorkflowIfDefined(
                workflowCode: 'HR_TERMINATION_OFFBOARDING',
                approvable: $lifecycleEvent,
                context: [
                    'employee_number' => $locked->employee_number,
                    'termination_date' => $dto->terminationDate,
                ],
                tenantId: (string) $locked->tenant_id,
                actorUserId: $actorUserId
            );

            $this->auditLedger->record(
                action: 'HR_EMPLOYEE_TERMINATED',
                model: $locked,
                payloadBefore: $before,
                payloadAfter: $locked->fresh()->toArray(),
                moduleCode: 'MODULE_18_HR'
            );

            $termEvent = new EmployeeTerminatedEvent(
                employeeId: (string) $locked->id,
                employeeNumber: (string) $locked->employee_number,
                terminationDate: $dto->terminationDate,
                reason: $dto->reason,
                tenantId: (string) $locked->tenant_id,
                companyId: (string) ($locked->company_id ?? $locked->tenant_id)
            );
            $this->dispatcher->publish($termEvent);
            event($termEvent);

            return $locked->fresh();
        });
    }

    public function rehireEmployee(Employee $employee, float $newSalary, ?string $rehireDate = null, ?string $actorUserId = null): Employee
    {
        return DB::transaction(function () use ($employee, $newSalary, $rehireDate, $actorUserId) {
            $locked = Employee::where('id', $employee->id)->lockForUpdate()->firstOrFail();
            $before = $locked->toArray();
            $rDate = $rehireDate ?: date('Y-m-d');
            $actorUuid = ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : null;
            $hourlyCost = round(($newSalary / 2080.0) * 1.35, 2);

            $locked->update([
                'employment_status' => 'active',
                'rehire_date' => $rDate,
                'termination_date' => null,
                'termination_reason' => null,
                'base_annual_salary' => $newSalary,
                'hourly_cost_rate' => $hourlyCost,
            ]);

            EmploymentContract::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $locked->tenant_id,
                'company_id' => $locked->company_id ?? $locked->tenant_id,
                'employee_id' => $locked->id,
                'contract_number' => 'CTR-REHIRE-'.strtoupper(Str::random(6)),
                'contract_type' => 'permanent',
                'job_title' => 'Rehired Specialist',
                'job_grade_id' => $locked->job_grade_id,
                'start_date' => $rDate,
                'weekly_hours' => 40.00,
                'fte_percentage' => 100.00,
                'base_annual_salary' => $newSalary,
                'hourly_rate' => round($newSalary / 2080.0, 2),
                'loaded_hourly_cost' => $hourlyCost,
                'currency' => (string) $locked->currency,
                'status' => 'active',
                'is_active' => true,
            ]);

            EmployeeLifecycleEvent::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $locked->tenant_id,
                'company_id' => $locked->company_id ?? $locked->tenant_id,
                'employee_id' => $locked->id,
                'event_type' => 'rehire',
                'effective_date' => $rDate,
                'from_status' => $before['employment_status'],
                'to_status' => 'active',
                'from_salary' => 0.00,
                'to_salary' => $newSalary,
                'reason' => 'Employee Rehired',
                'initiated_by_user_id' => $actorUuid,
            ]);

            $this->auditLedger->record(
                action: 'HR_EMPLOYEE_REHIRED',
                model: $locked,
                payloadBefore: $before,
                payloadAfter: $locked->fresh()->toArray(),
                moduleCode: 'MODULE_18_HR'
            );

            return $locked->fresh();
        });
    }

    public function recordAssetAssignment(Employee $employee, string $assetId, string $assetAction, array $assetDetails = []): Employee
    {
        return DB::transaction(function () use ($employee, $assetId, $assetAction, $assetDetails) {
            $locked = Employee::where('id', $employee->id)->lockForUpdate()->firstOrFail();
            $equipment = is_array($locked->equipment_assigned) ? $locked->equipment_assigned : [];

            $equipment[$assetId] = array_merge($assetDetails, [
                'asset_id' => $assetId,
                'last_action' => $assetAction,
                'updated_at' => now()->toIso8601String(),
            ]);

            $locked->update(['equipment_assigned' => $equipment]);

            return $locked->fresh();
        });
    }

    private function tryStartWorkflowIfDefined(
        string $workflowCode,
        object $approvable,
        array $context,
        string $tenantId,
        ?string $actorUserId
    ): bool {
        if ($this->workflowEngine === null) {
            return false;
        }

        $hasDef = WorkflowDefinition::where('tenant_id', $tenantId)
            ->where('code', $workflowCode)
            ->where('is_active', true)
            ->exists();

        if (! $hasDef) {
            return false;
        }

        try {
            $initiatorId = ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : (string) Str::uuid7();
            $instance = $this->workflowEngine->startWorkflow(new StartWorkflowDTO(
                workflowCode: $workflowCode,
                approvable: $approvable,
                contextSnapshot: $context,
                tenantId: $tenantId,
                companyId: $tenantId,
                initiatorUserId: $initiatorId
            ));

            if (method_exists($approvable, 'lockByWorkflow')) {
                $approvable->lockByWorkflow($instance);
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
