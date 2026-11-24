<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\ServiceManagement\Contracts\ProblemAndChangeServiceInterface;
use Modules\ServiceManagement\DTOs\CreateChangeRequestDTO;
use Modules\ServiceManagement\DTOs\CreateProblemDTO;
use Modules\ServiceManagement\Events\ChangeApprovedEvent;
use Modules\ServiceManagement\Events\ChangeRequestCreatedEvent;
use Modules\ServiceManagement\Events\ProblemCreatedEvent;
use Modules\ServiceManagement\Events\ProblemResolvedEvent;
use Modules\ServiceManagement\Models\ChangeRequest;
use Modules\ServiceManagement\Models\Problem;

final readonly class ProblemAndChangeService implements ProblemAndChangeServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function createProblem(CreateProblemDTO $dto, ?string $actorUserId = null): Problem
    {
        return DB::transaction(function () use ($dto) {
            $problemNumber = 'PRB-'.date('Ymd').'-'.strtoupper(Str::random(6));

            $problem = Problem::create([
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId,
                'problem_number' => $problemNumber,
                'title' => $dto->title,
                'description' => $dto->description ?? $dto->title,
                'status' => 'under_investigation',
                'category_id' => $dto->serviceCategoryId,
                'root_cause' => $dto->rootCauseAnalysis,
                'workaround' => $dto->workaround,
                'is_known_error' => false,
            ]);

            $this->auditLedger->record(
                'PROBLEM_CREATED',
                $problem,
                null,
                ['problem_number' => $problemNumber, 'priority' => $dto->priority],
                'MODULE_17_ESM'
            );

            $this->dispatcher->dispatch(new ProblemCreatedEvent(
                problemId: $problem->id,
                problemNumber: $problem->problem_number,
                title: $problem->title,
                priority: $dto->priority,
                tenantId: $problem->tenant_id,
                companyId: $problem->company_id
            ));

            return $problem;
        });
    }

    public function resolveProblem(string $problemId, ?string $workaround = null, ?string $permanentSolution = null, ?string $actorUserId = null): Problem
    {
        return DB::transaction(function () use ($problemId, $workaround, $permanentSolution) {
            $problem = Problem::findOrFail($problemId);

            $oldValues = ['status' => $problem->status];

            $problem->update([
                'status' => 'resolved',
                'workaround' => $workaround ?? $problem->workaround,
                'permanent_solution' => $permanentSolution ?? $problem->permanent_solution,
                'is_known_error' => false,
                'resolved_at' => now(),
            ]);

            $this->auditLedger->record(
                'PROBLEM_RESOLVED',
                $problem,
                $oldValues,
                ['permanent_solution' => $permanentSolution],
                'MODULE_17_ESM'
            );

            $this->dispatcher->dispatch(new ProblemResolvedEvent(
                problemId: $problem->id,
                problemNumber: $problem->problem_number,
                workaround: $workaround,
                permanentSolution: $permanentSolution,
                tenantId: $problem->tenant_id,
                companyId: $problem->company_id
            ));

            return $problem;
        });
    }

    public function createChangeRequest(CreateChangeRequestDTO $dto, ?string $actorUserId = null): ChangeRequest
    {
        return DB::transaction(function () use ($dto) {
            $changeNumber = 'CR-'.date('Ymd').'-'.strtoupper(Str::random(6));

            $change = ChangeRequest::create([
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId,
                'change_number' => $changeNumber,
                'title' => $dto->title,
                'description' => $dto->description ?? $dto->title,
                'change_type' => $dto->changeType,
                'risk_level' => $dto->riskLevel,
                'status' => 'submitted',
                'impact_assessment' => 'Low blast radius confined to microservice nodes.',
                'implementation_plan' => $dto->rolloutPlan ?? 'Apply rolling canary deployment.',
                'rollback_plan' => $dto->rollbackPlan ?? 'Rollback traffic to stable version.',
                'scheduled_start_at' => $dto->scheduledStartTime,
                'scheduled_end_at' => $dto->scheduledEndTime,
            ]);

            $this->auditLedger->record(
                'CHANGE_REQUEST_CREATED',
                $change,
                null,
                ['change_number' => $changeNumber, 'change_type' => $dto->changeType],
                'MODULE_17_ESM'
            );

            $this->dispatcher->dispatch(new ChangeRequestCreatedEvent(
                changeRequestId: $change->id,
                changeNumber: $change->change_number,
                title: $change->title,
                changeType: $change->change_type,
                riskLevel: $change->risk_level,
                tenantId: $change->tenant_id,
                companyId: $change->company_id
            ));

            return $change;
        });
    }

    public function approveChangeRequest(string $changeRequestId, string $approvedBy, ?string $actorUserId = null): ChangeRequest
    {
        return DB::transaction(function () use ($changeRequestId, $approvedBy) {
            $change = ChangeRequest::findOrFail($changeRequestId);

            if ($change->status === 'approved') {
                return $change;
            }

            $oldValues = ['status' => $change->status];

            $change->update([
                'status' => 'approved',
                'cab_decision' => 'approved',
                'cab_notes' => 'Approved by CAB lead '.$approvedBy,
            ]);

            $this->auditLedger->record(
                'CHANGE_REQUEST_APPROVED',
                $change,
                $oldValues,
                ['approved_by' => $approvedBy],
                'MODULE_17_ESM'
            );

            $this->dispatcher->dispatch(new ChangeApprovedEvent(
                changeRequestId: $change->id,
                changeNumber: $change->change_number,
                approvedBy: $approvedBy,
                tenantId: $change->tenant_id,
                companyId: $change->company_id
            ));

            return $change;
        });
    }

    public function implementChangeRequest(string $changeRequestId, ?string $actorUserId = null): ChangeRequest
    {
        return DB::transaction(function () use ($changeRequestId) {
            $change = ChangeRequest::findOrFail($changeRequestId);

            if ($change->status !== 'approved') {
                throw new InvalidArgumentException("Change {$change->change_number} must be approved before implementation.");
            }

            $change->update([
                'status' => 'implemented',
                'implemented_at' => now(),
            ]);

            $this->auditLedger->record(
                'CHANGE_REQUEST_IMPLEMENTED',
                $change,
                null,
                [],
                'MODULE_17_ESM'
            );

            return $change;
        });
    }
}
