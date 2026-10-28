<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Contracts\EventBusInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\RecordDecisionDTO;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Events\WorkflowDecisionRecordedEvent;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;
use Modules\Workflow\Events\WorkflowInstanceStartedEvent;
use Modules\Workflow\Events\WorkflowStepAssignedEvent;
use Modules\Workflow\Models\ApprovalDecision;
use Modules\Workflow\Models\ApprovalMatrix;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowDelegation;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;
use Modules\Workflow\Models\WorkflowNode;
use Modules\Workflow\Models\WorkflowTransition;

final class WorkflowEngineService implements WorkflowEngineInterface
{
    public function __construct(
        private readonly ApprovalMatrixEvaluatorService $matrixEvaluator,
        private readonly BusinessCalendarCalculationService $calendarCalculator,
        private readonly VotingThresholdEvaluationService $votingEvaluator,
        private readonly WorkflowAuditLedgerService $auditLedger,
        private readonly DocumentLockingBridgeService $lockingBridge,
        private readonly WorkflowWebhookDispatcherService $webhookDispatcher,
        private readonly ?EventBusInterface $eventBus = null
    ) {}

    public function start(StartWorkflowDTO $dto): WorkflowInstance
    {
        $tenantId = $dto->tenantId
            ?? $dto->approvable->getAttribute('tenant_id')
            ?? app(TenantContext::class)->getTenantId();

        $definition = WorkflowDefinition::where('tenant_id', $tenantId)
            ->where('code', $dto->workflowCode)
            ->where('is_active', true)
            ->with(['nodes.outgoingTransitions', 'transitions'])
            ->first();

        if ($definition === null) {
            throw new InvalidArgumentException("Active workflow definition [{$dto->workflowCode}] not found for tenant [{$tenantId}].");
        }

        $startNode = $definition->getStartNode();
        if ($startNode === null) {
            throw new InvalidArgumentException("Workflow definition [{$dto->workflowCode}] does not have a start node.");
        }

        return DB::transaction(function () use ($dto, $definition, $startNode, $tenantId) {
            // Concurrency Protection: Prevent duplicate active workflow instances for the same approvable document
            $existingActive = WorkflowInstance::where('tenant_id', $tenantId)
                ->where('approvable_type', $dto->approvable::class)
                ->where('approvable_id', (string) $dto->approvable->getKey())
                ->whereIn('status', ['pending', 'in_progress'])
                ->lockForUpdate()
                ->first();

            if ($existingActive !== null) {
                throw new InvalidArgumentException("Active workflow instance [{$existingActive->id}] is already in progress for this document.");
            }

            $definitionSnapshot = [
                'id' => $definition->id,
                'code' => $definition->code,
                'version' => $definition->version,
                'nodes' => $definition->nodes->toArray(),
                'transitions' => $definition->transitions->toArray(),
            ];

            $instance = WorkflowInstance::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'company_id' => $dto->companyId ?? $dto->approvable->getAttribute('company_id'),
                'workflow_definition_id' => $definition->id,
                'workflow_definition_version' => $definition->version,
                'workflow_definition_snapshot' => $definitionSnapshot,
                'approvable_type' => $dto->approvable::class,
                'approvable_id' => (string) $dto->approvable->getKey(),
                'current_node_id' => $startNode->id,
                'status' => 'pending',
                'context_snapshot' => $dto->contextSnapshot,
                'started_at' => now(),
                'created_by' => $dto->initiatorUserId,
            ]);

            $this->auditLedger->record(
                instance: $instance,
                eventType: 'instance_started',
                payload: ['definition_code' => $definition->code, 'version' => $definition->version],
                actorUserId: $dto->initiatorUserId
            );

            $this->dispatchDomainEvent(new WorkflowInstanceStartedEvent(
                instanceId: $instance->id,
                workflowCode: $definition->code,
                approvableType: $dto->approvable::class,
                approvableId: (string) $dto->approvable->getKey(),
                tenantId: $tenantId,
                companyId: (string) ($instance->company_id ?? $tenantId)
            ));

            // Immediately transition from start node to next nodes
            $this->advance($instance, 'start');

            return $instance;
        });
    }

    public function recordDecision(RecordDecisionDTO $dto): WorkflowInstanceStep
    {
        return DB::transaction(function () use ($dto) {
            $step = WorkflowInstanceStep::where('id', $dto->workflowInstanceStepId)
                ->lockForUpdate()
                ->with(['instance.definition', 'node'])
                ->firstOrFail();

            $instance = WorkflowInstance::where('id', $step->workflow_instance_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($step->status !== 'pending') {
                throw new InvalidArgumentException("Workflow step [{$step->id}] is not pending (status: {$step->status}).");
            }

            $userId = $dto->userId ?? auth()->id() ?? '00000000-0000-0000-0000-000000000000';
            $effectiveUserId = $this->resolveEffectiveUserId($step, $userId);

            // Check idempotency if key provided
            if ($dto->idempotencyKey !== null) {
                $existing = ApprovalDecision::where('workflow_instance_step_id', $step->id)
                    ->where('idempotency_key', $dto->idempotencyKey)
                    ->first();
                if ($existing !== null) {
                    return $step; // Idempotent return
                }
            }

            ApprovalDecision::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $instance->tenant_id,
                'workflow_instance_step_id' => $step->id,
                'user_id' => $userId,
                'effective_user_id' => $effectiveUserId,
                'decision' => strtolower($dto->decision),
                'comment' => $dto->comment,
                'ip_address' => $dto->ipAddress ?? request()->ip(),
                'user_agent' => substr((string) ($dto->userAgent ?? request()->userAgent()), 0, 500),
                'signature_payload' => $dto->signaturePayload,
                'idempotency_key' => $dto->idempotencyKey,
                'approval_token_id' => $dto->approvalTokenId,
                'decided_at' => now(),
            ]);

            $step->update([
                'status' => strtolower($dto->decision) === 'approved' ? 'approved' : 'rejected',
                'completed_at' => now(),
            ]);

            $this->auditLedger->record(
                instance: $instance,
                eventType: 'decision_recorded',
                payload: ['decision' => $dto->decision, 'step_id' => $step->id, 'comment' => $dto->comment],
                step: $step,
                actorUserId: $effectiveUserId
            );

            $this->dispatchDomainEvent(new WorkflowDecisionRecordedEvent(
                instanceId: $instance->id,
                stepId: $step->id,
                decision: $dto->decision,
                userId: $effectiveUserId,
                tenantId: $instance->tenant_id,
                companyId: (string) ($instance->company_id ?? $instance->tenant_id)
            ));

            // Check if node threshold is attained
            $nodeSteps = WorkflowInstanceStep::where('workflow_instance_id', $instance->id)
                ->where('workflow_node_id', $step->workflow_node_id)
                ->with('decisions')
                ->get();

            $eval = $this->votingEvaluator->evaluate($step->node, $nodeSteps);

            if ($eval['completed']) {
                $this->advance($instance, $eval['outcome']);
            }

            return $step;
        });
    }

    public function advance(WorkflowInstance $instance, string $actionTrigger): void
    {
        $currentNode = $instance->currentNode;
        if ($currentNode === null) {
            return;
        }

        // Find candidate outgoing transitions matching the action trigger
        $transitions = WorkflowTransition::where('from_node_id', $currentNode->id)
            ->where(function ($q) use ($actionTrigger): void {
                $q->where('action_trigger', $actionTrigger)
                    ->orWhere('action_trigger', 'condition_true')
                    ->orWhere('action_trigger', '*');
            })
            ->orderBy('priority', 'asc')
            ->get();

        $selectedTransition = null;
        foreach ($transitions as $transition) {
            if ($this->evaluateTransitionRules($transition, $instance)) {
                $selectedTransition = $transition;
                break;
            }
        }

        if ($selectedTransition === null) {
            if ($actionTrigger === 'rejected') {
                $instance->update(['status' => 'rejected', 'completed_at' => now()]);
                $this->dispatchDomainEvent(new WorkflowInstanceRejectedEvent(
                    instanceId: $instance->id,
                    tenantId: $instance->tenant_id,
                    companyId: (string) ($instance->company_id ?? $instance->tenant_id)
                ));
            }

            return;
        }

        $targetNode = $selectedTransition->toNode;
        $instance->update(['current_node_id' => $targetNode->id]);

        if ($targetNode->node_type === 'end') {
            $isApproved = $actionTrigger !== 'rejected';
            $instance->update([
                'status' => $isApproved ? 'approved' : 'rejected',
                'completed_at' => now(),
            ]);

            if ($isApproved) {
                // Fiscal locking if configured
                if ($currentNode->locks_approvable_on_completion || $instance->approvable instanceof LockableByWorkflowInterface) {
                    $this->lockingBridge->executeFiscalLock($instance);
                }

                $this->dispatchDomainEvent(new WorkflowInstanceCompletedEvent(
                    instanceId: $instance->id,
                    status: 'approved',
                    tenantId: $instance->tenant_id,
                    companyId: (string) ($instance->company_id ?? $instance->tenant_id)
                ));

                $this->webhookDispatcher->dispatch(
                    tenantId: $instance->tenant_id,
                    eventName: 'workflow.completed',
                    payload: ['instance_id' => $instance->id, 'status' => 'approved', 'approvable_id' => $instance->approvable_id]
                );
            }

            return;
        }

        if ($targetNode->node_type === 'approval') {
            $this->createStepsForNode($instance, $targetNode);
        } elseif ($targetNode->node_type === 'conditional') {
            $this->advance($instance, 'condition_true');
        }
    }

    public function processTimeouts(): int
    {
        $dueSteps = WorkflowInstanceStep::where('status', 'pending')
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now())
            ->with(['instance', 'node'])
            ->get();

        $count = 0;
        foreach ($dueSteps as $step) {
            $step->update([
                'status' => 'timed_out',
                'completed_at' => now(),
            ]);

            $this->auditLedger->record(
                instance: $step->instance,
                eventType: 'step_timed_out',
                payload: ['step_id' => $step->id, 'due_at' => $step->due_at?->toIso8601String()],
                step: $step
            );

            // Escalate or advance
            $this->advance($step->instance, 'timeout');
            $count++;
        }

        return $count;
    }

    public function cancel(WorkflowInstance $instance, ?string $reason = null): void
    {
        $instance->update([
            'status' => 'cancelled',
            'completed_at' => now(),
        ]);

        $instance->steps()->where('status', 'pending')->update([
            'status' => 'cancelled',
            'completed_at' => now(),
        ]);

        $this->auditLedger->record(
            instance: $instance,
            eventType: 'instance_cancelled',
            payload: ['reason' => $reason]
        );
    }

    private function createStepsForNode(WorkflowInstance $instance, WorkflowNode $node): void
    {
        $timeoutHours = $node->timeout_hours ?? 48;
        $dueAt = $this->calendarCalculator->calculateDeadline(now(), $timeoutHours, $node->calendar);

        if ($node->assignee_type === 'matrix_lookup') {
            $matrix = ApprovalMatrix::where('tenant_id', $instance->tenant_id)
                ->where('target_entity_type', $instance->approvable_type)
                ->where('is_active', true)
                ->with(['rules', 'tiers'])
                ->first();

            if ($matrix !== null) {
                $tiers = $this->matrixEvaluator->evaluate($matrix, $instance->approvable, $instance->context_snapshot);
                foreach ($tiers as $tier) {
                    $this->createStep(
                        instance: $instance,
                        node: $node,
                        userId: $tier['approver_type'] === 'user' ? $tier['approver_value'] : null,
                        role: $tier['approver_type'] === 'role' ? $tier['approver_value'] : null,
                        tierLevel: $tier['tier_level'],
                        dueAt: $dueAt
                    );
                }

                return;
            }
        }

        // Standard user or role assignment
        $assignedUserId = $node->assignee_type === 'direct_user' ? $node->assignee_id : null;
        $assignedRole = $node->assignee_type === 'role' ? $node->assignee_id : null;

        $this->createStep(
            instance: $instance,
            node: $node,
            userId: $assignedUserId,
            role: $assignedRole,
            tierLevel: 1,
            dueAt: $dueAt
        );
    }

    private function createStep(
        WorkflowInstance $instance,
        WorkflowNode $node,
        ?string $userId,
        ?string $role,
        int $tierLevel,
        Carbon $dueAt
    ): WorkflowInstanceStep {
        $step = WorkflowInstanceStep::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $instance->tenant_id,
            'workflow_instance_id' => $instance->id,
            'workflow_node_id' => $node->id,
            'assigned_user_id' => $userId,
            'assigned_role' => $role,
            'status' => 'pending',
            'tier_level' => $tierLevel,
            'due_at' => $dueAt,
        ]);

        $this->auditLedger->record(
            instance: $instance,
            eventType: 'step_assigned',
            payload: ['step_id' => $step->id, 'assigned_user_id' => $userId, 'role' => $role],
            step: $step
        );

        $this->dispatchDomainEvent(new WorkflowStepAssignedEvent(
            instanceId: $instance->id,
            stepId: $step->id,
            assignedUserId: $userId,
            assignedRole: $role,
            dueAt: $dueAt->toIso8601String(),
            tenantId: $instance->tenant_id,
            companyId: (string) ($instance->company_id ?? $instance->tenant_id)
        ));

        return $step;
    }

    private function evaluateTransitionRules(WorkflowTransition $transition, WorkflowInstance $instance): bool
    {
        $rules = $transition->condition_rules ?? [];
        if (empty($rules)) {
            return true;
        }

        $context = $instance->context_snapshot;
        foreach ($rules as $rule) {
            $field = $rule['field'] ?? null;
            $op = $rule['operator'] ?? '=';
            $expected = $rule['value'] ?? null;

            if ($field === null) {
                continue;
            }

            $val = $context[$field] ?? $instance->approvable->getAttribute($field);

            $matched = match ($op) {
                '=' => (string) $val === (string) $expected,
                '!=' => (string) $val !== (string) $expected,
                '>' => (float) $val > (float) $expected,
                '>=' => (float) $val >= (float) $expected,
                '<' => (float) $val < (float) $expected,
                '<=' => (float) $val <= (float) $expected,
                default => true,
            };

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    private function resolveEffectiveUserId(WorkflowInstanceStep $step, string $actingUserId): string
    {
        $assignedUserId = (string) $step->assigned_user_id;

        // 1. Direct match with assigned user
        if ($assignedUserId !== '' && $assignedUserId === $actingUserId) {
            return $actingUserId;
        }

        // 2. Direct Role Match
        if ($step->assigned_role !== null) {
            if ($this->userHasRole($actingUserId, $step->tenant_id, $step->assigned_role)) {
                return $actingUserId;
            }
        }

        // 3. Active Delegation Match
        if ($assignedUserId !== '') {
            $delegation = WorkflowDelegation::where('delegator_user_id', $assignedUserId)
                ->where('delegate_user_id', $actingUserId)
                ->where('is_active', true)
                ->where('starts_at', '<=', now())
                ->where('ends_at', '>=', now())
                ->first();

            if ($delegation !== null) {
                return $actingUserId;
            }
        }

        // 4. Admin override
        if ($this->userHasRole($actingUserId, $step->tenant_id, 'admin')) {
            return $actingUserId;
        }

        throw new AuthorizationException("Acting user [{$actingUserId}] is not authorized to decide on step [{$step->id}].");
    }

    private function userHasRole(string $userId, string $tenantId, string $role): bool
    {
        // Check tenant_user membership role
        if (DB::getSchemaBuilder()->hasTable('tenant_user')) {
            $membership = DB::table('tenant_user')
                ->where('user_id', $userId)
                ->where('tenant_id', $tenantId)
                ->first();

            if ($membership !== null && ($membership->role === $role || $membership->role === 'admin' || $membership->role === 'owner')) {
                return true;
            }
        }

        // Fallback to Spatie if tables exist
        if (DB::getSchemaBuilder()->hasTable('roles')) {
            try {
                $user = User::find($userId);
                if ($user !== null && method_exists($user, 'hasRole') && $user->hasRole($role)) {
                    return true;
                }
            } catch (\Throwable) {
                // Table unmigrated or relation unavailable
            }
        }

        return false;
    }

    private function dispatchDomainEvent(object $event): void
    {
        if ($this->eventBus !== null && $event instanceof DomainEventInterface) {
            $this->eventBus->publish($event);
        } else {
            event($event);
        }
    }
}
