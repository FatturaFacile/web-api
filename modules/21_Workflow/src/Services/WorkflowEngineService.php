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
use Modules\Workflow\Contracts\ReactsToWorkflowOutcome;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\RecordDecisionDTO;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Events\WorkflowDecisionRecordedEvent;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceExpiredEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;
use Modules\Workflow\Events\WorkflowInstanceStartedEvent;
use Modules\Workflow\Events\WorkflowStepAssignedEvent;
use Modules\Workflow\Events\WorkflowStepEscalatedEvent;
use Modules\Workflow\Events\WorkflowStepReminderEvent;
use Modules\Workflow\Models\ApprovalDecision;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowDelegation;
use Modules\Workflow\Models\WorkflowEscalationRule;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;
use Modules\Workflow\Models\WorkflowNode;
use Modules\Workflow\Models\WorkflowTransition;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The state machine of approvals. Callers (HR, Procurement, Manufacturing, quotes, documents) start an instance with
 * {@see start()} and are told the outcome through `ReactsToWorkflowOutcome`.
 *
 * Robustness rules of the engine:
 * - steps still pending on a node are closed (`skipped`) when the node is left, so nobody decides on a step that no
 *   longer counts, and a decision is accepted only while the instance is active;
 * - a step that is not decided in time is timed out; the definition's `timeout` path is taken when it has one, otherwise
 *   the node is evaluated with the votes it got, and an instance that cannot complete expires instead of hanging;
 * - reminders, escalations and automatic decisions come from the escalation rule of the node (see
 *   {@see WorkflowTimeoutProcessor});
 * - a node can be assigned to a user, a role, the reporting manager of the initiator, a matrix, or a list of users
 *   deciding in parallel or one after the other.
 */
final class WorkflowEngineService implements WorkflowEngineInterface
{
    /**
     * Instance statuses in which steps can still be decided.
     *
     * @var list<string>
     */
    private const array ActiveStatuses = ['pending', 'in_progress'];

    /**
     * Actor recorded on decisions the engine takes by itself (automatic approval or rejection of an escalation rule).
     */
    public const string SystemActor = '00000000-0000-0000-0000-000000000000';

    /**
     * Inject the collaborators.
     */
    public function __construct(
        private readonly ApprovalMatrixEvaluatorService $matrixEvaluator,
        private readonly BusinessCalendarCalculationService $calendarCalculator,
        private readonly VotingThresholdEvaluationService $votingEvaluator,
        private readonly WorkflowAuditLedgerService $auditLedger,
        private readonly DocumentLockingBridgeService $lockingBridge,
        private readonly WorkflowWebhookDispatcherService $webhookDispatcher,
        private readonly WorkflowAssigneeResolver $assignees,
        private readonly ?EventBusInterface $eventBus = null
    ) {}

    /**
     * {@inheritDoc}
     */
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
                ->whereIn('status', self::ActiveStatuses)
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

    /**
     * {@inheritDoc}
     */
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

            // A retry with the same key is answered with the state the first attempt left, even after the step closed.
            if ($dto->idempotencyKey !== null) {
                $existing = ApprovalDecision::where('workflow_instance_step_id', $step->id)
                    ->where('idempotency_key', $dto->idempotencyKey)
                    ->first();
                if ($existing !== null) {
                    return $step;
                }
            }

            $this->assertActive($instance);

            if ($step->status !== 'pending') {
                throw new InvalidArgumentException("Workflow step [{$step->id}] is not pending (status: {$step->status}).");
            }

            $userId = $dto->userId ?? auth()->id() ?? self::SystemActor;
            $effectiveUserId = $this->resolveEffectiveUserId($step, $userId);

            $this->assertFourEyes($instance, $userId, $effectiveUserId);

            $this->commitDecision(
                $instance,
                $step,
                $userId,
                $effectiveUserId,
                strtolower($dto->decision),
                $dto->comment,
                [
                    'ip_address' => $dto->ipAddress ?? request()->ip(),
                    'user_agent' => substr((string) ($dto->userAgent ?? request()->userAgent()), 0, 500),
                    'signature_payload' => $dto->signaturePayload,
                    'idempotency_key' => $dto->idempotencyKey,
                    'approval_token_id' => $dto->approvalTokenId,
                ]
            );

            return $step;
        });
    }

    /**
     * Records the decision an escalation rule takes when a step runs out of time (`auto_approve` or `auto_reject`). It is
     * a normal decision of the engine's own actor, marked as automatic in the audit trail, and it skips the checks that
     * apply to people (assignee, four-eyes).
     *
     * @throws InvalidArgumentException when the step is not pending or its instance is not active
     */
    public function recordSystemDecision(WorkflowInstanceStep $step, string $decision, string $reason): WorkflowInstanceStep
    {
        return DB::transaction(function () use ($step, $decision, $reason): WorkflowInstanceStep {
            $locked = WorkflowInstanceStep::where('id', $step->id)->lockForUpdate()->with(['instance', 'node'])->firstOrFail();
            $instance = WorkflowInstance::where('id', $locked->workflow_instance_id)->lockForUpdate()->firstOrFail();
            $this->assertActive($instance);

            if ($locked->status !== 'pending') {
                throw new InvalidArgumentException("Workflow step [{$locked->id}] is not pending (status: {$locked->status}).");
            }

            $this->commitDecision($instance, $locked, self::SystemActor, self::SystemActor, strtolower($decision), $reason, [], ['automatic' => true]);

            return $locked;
        });
    }

    /**
     * Four-eyes rule of an instance started with `four_eyes` in its context: whoever prepared the document cannot
     * approve it, and nobody approves it twice. It binds administrators too.
     *
     * @throws AuthorizationException
     */
    private function assertFourEyes(WorkflowInstance $instance, string $userId, string $effectiveUserId): void
    {
        $context = $instance->context_snapshot ?? [];

        if (($context['four_eyes'] ?? false) !== true) {
            return;
        }

        $makers = array_filter([(string) $instance->created_by, ...($context['maker_user_ids'] ?? [])]);

        if (array_intersect([$userId, $effectiveUserId], $makers) !== []) {
            throw new AuthorizationException('Four-eyes rule: who prepared this document cannot approve it.');
        }

        $alreadyDecided = ApprovalDecision::whereIn('workflow_instance_step_id', $instance->steps()->pluck('id'))
            ->where(fn ($query) => $query->whereIn('user_id', [$userId, $effectiveUserId])->orWhereIn('effective_user_id', [$userId, $effectiveUserId]))
            ->exists();

        if ($alreadyDecided) {
            throw new AuthorizationException('Four-eyes rule: an approver can decide only once on a document.');
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertActive(WorkflowInstance $instance): void
    {
        if (! in_array($instance->status, self::ActiveStatuses, true)) {
            throw new InvalidArgumentException("Workflow instance [{$instance->id}] is not active (status: {$instance->status}).");
        }
    }

    /**
     * Stores a decision, closes the step and lets the node move on once its voting rule is met.
     *
     * @param  array<string, mixed>  $columns  further columns of the decision row (network data, signature, idempotency key)
     * @param  array<string, mixed>  $auditExtra
     */
    private function commitDecision(WorkflowInstance $instance, WorkflowInstanceStep $step, string $userId, string $effectiveUserId, string $decision, ?string $comment, array $columns, array $auditExtra = []): void
    {
        ApprovalDecision::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $instance->tenant_id,
            'workflow_instance_step_id' => $step->id,
            'user_id' => $userId,
            'effective_user_id' => $effectiveUserId,
            'decision' => $decision,
            'comment' => $comment,
            ...$columns,
            'decided_at' => now(),
        ]);

        $step->update([
            'status' => $decision === 'approved' ? 'approved' : 'rejected',
            'completed_at' => now(),
        ]);

        $this->auditLedger->record(
            instance: $instance,
            eventType: 'decision_recorded',
            payload: ['decision' => $decision, 'step_id' => $step->id, 'comment' => $comment, ...$auditExtra],
            step: $step,
            actorUserId: $effectiveUserId === self::SystemActor ? null : $effectiveUserId
        );

        $this->dispatchDomainEvent(new WorkflowDecisionRecordedEvent(
            instanceId: $instance->id,
            stepId: $step->id,
            decision: $decision,
            userId: $effectiveUserId,
            tenantId: $instance->tenant_id,
            companyId: (string) ($instance->company_id ?? $instance->tenant_id)
        ));

        $this->resolveNode($instance, $step);
    }

    /**
     * Applies the voting rule of the node to the steps decided so far. Returns whether the node was completed.
     */
    private function resolveNode(WorkflowInstance $instance, WorkflowInstanceStep $step): bool
    {
        $nodeSteps = WorkflowInstanceStep::where('workflow_instance_id', $instance->id)
            ->where('workflow_node_id', $step->workflow_node_id)
            ->with('decisions')
            ->get();

        $eval = $this->votingEvaluator->evaluate($step->node, $nodeSteps);

        if (! $eval['completed']) {
            return false;
        }

        if ($eval['outcome'] === 'approved' && $this->openNextTier($instance, $step)) {
            return true;
        }

        $this->advance($instance, $eval['outcome']);

        return true;
    }

    /**
     * A node assigned to a matrix with sequential tiers, or to a sequential list of users, opens one level at a time: the
     * next level starts once the current one is approved. Steps of the finished level that are still pending are closed.
     */
    private function openNextTier(WorkflowInstance $instance, WorkflowInstanceStep $step): bool
    {
        $node = $step->node;

        if ($node->assignee_type === 'user_list_sequential') {
            $users = $this->assignees->userList($node->assignee_id);
            $nextUser = $users[(int) $step->tier_level] ?? null;

            if ($nextUser === null) {
                return false;
            }

            $this->skipPendingUpTo($instance, $node, (int) $step->tier_level);
            $this->createStep($instance, $node, $nextUser, null, (int) $step->tier_level + 1, $this->dueAtFor($node));

            return true;
        }

        if ($node->assignee_type !== 'matrix_sequential') {
            return false;
        }

        $tiers = collect($this->matrixEvaluator->tiersFor((string) $instance->tenant_id, (string) $instance->approvable_type, $instance->approvable, $instance->context_snapshot) ?? [])
            ->where('tier_level', '>', (int) $step->tier_level);

        if ($tiers->isEmpty()) {
            return false;
        }

        $this->skipPendingUpTo($instance, $node, (int) $step->tier_level);
        $this->createTierSteps($instance, $node, $tiers->where('tier_level', $tiers->min('tier_level'))->values()->all());

        return true;
    }

    private function skipPendingUpTo(WorkflowInstance $instance, WorkflowNode $node, int $tierLevel): void
    {
        WorkflowInstanceStep::where('workflow_instance_id', $instance->id)
            ->where('workflow_node_id', $node->id)
            ->where('status', 'pending')
            ->where('tier_level', '<=', $tierLevel)
            ->update(['status' => 'skipped', 'completed_at' => now()]);
    }

    /**
     * @param  array<int, array{tier_level: int, approver_type: string, approver_value: string, approval_type: string}>  $tiers
     */
    private function createTierSteps(WorkflowInstance $instance, WorkflowNode $node, array $tiers): void
    {
        $dueAt = $this->dueAtFor($node);

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
    }

    private function dueAtFor(WorkflowNode $node, ?Carbon $from = null): Carbon
    {
        return $this->calendarCalculator->calculateDeadline($from ?? now(), $node->timeout_hours ?? 48, $node->calendar);
    }

    private function notifyApprovable(WorkflowInstance $instance, string $outcome): void
    {
        $approvable = $instance->approvable;

        if ($approvable instanceof ReactsToWorkflowOutcome) {
            $approvable->onWorkflowOutcome($instance, $outcome);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function advance(WorkflowInstance $instance, string $actionTrigger): void
    {
        $this->tryAdvance($instance, $actionTrigger);
    }

    /**
     * Takes the first transition of the current node that matches the trigger and whose conditions hold. Returns false
     * when there is none (and the trigger is not a rejection, which always ends the instance).
     */
    private function tryAdvance(WorkflowInstance $instance, string $actionTrigger): bool
    {
        $currentNode = $instance->currentNode;
        if ($currentNode === null) {
            return false;
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
            if ($actionTrigger !== 'rejected') {
                return false;
            }

            $this->closeNodeSteps($instance, $currentNode);
            $instance->update(['status' => 'rejected', 'completed_at' => now()]);
            $this->notifyApprovable($instance, 'rejected');
            $this->dispatchDomainEvent(new WorkflowInstanceRejectedEvent(
                instanceId: $instance->id,
                tenantId: $instance->tenant_id,
                companyId: (string) ($instance->company_id ?? $instance->tenant_id)
            ));

            return true;
        }

        $this->closeNodeSteps($instance, $currentNode);

        $targetNode = $selectedTransition->toNode;
        $instance->update(['current_node_id' => $targetNode->id]);
        // The node the instance stands on changed: a chained `advance` (conditional nodes) must read the new one.
        $instance->unsetRelation('currentNode');

        if ($targetNode->node_type === 'end') {
            $isApproved = $actionTrigger !== 'rejected';
            $instance->update([
                'status' => $isApproved ? 'approved' : 'rejected',
                'completed_at' => now(),
            ]);

            $this->notifyApprovable($instance, $isApproved ? 'approved' : 'rejected');

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

            return true;
        }

        if ($targetNode->node_type === 'approval') {
            $this->createStepsForNode($instance, $targetNode);
        } elseif ($targetNode->node_type === 'conditional') {
            $this->advance($instance, 'condition_true');
        }

        return true;
    }

    /**
     * Steps still pending on a node being left no longer count: closing them keeps them out of every inbox and makes a
     * late decision on them impossible.
     */
    private function closeNodeSteps(WorkflowInstance $instance, WorkflowNode $node): void
    {
        $ids = WorkflowInstanceStep::where('workflow_instance_id', $instance->id)
            ->where('workflow_node_id', $node->id)
            ->where('status', 'pending')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        WorkflowInstanceStep::whereIn('id', $ids)->update(['status' => 'skipped', 'completed_at' => now()]);

        $this->auditLedger->record(
            instance: $instance,
            eventType: 'steps_skipped',
            payload: ['node_id' => $node->id, 'step_ids' => $ids->all()]
        );
    }

    /**
     * {@inheritDoc}
     *
     * Reminders, escalations, automatic decisions and timeouts of every pending step, see {@see WorkflowTimeoutProcessor}.
     */
    public function processTimeouts(): int
    {
        return array_sum(app(WorkflowTimeoutProcessor::class)->run());
    }

    /**
     * A pending step ran out of time. The step is timed out; then the instance moves on the way its definition says:
     * through a `timeout` (or `*`) transition when the node has one, otherwise by evaluating the node once no step of it
     * is pending any more, and finally by expiring when the node cannot reach an outcome.
     */
    public function handleStepTimeout(WorkflowInstanceStep $step): void
    {
        DB::transaction(function () use ($step): void {
            $locked = WorkflowInstanceStep::where('id', $step->id)->lockForUpdate()->with(['instance', 'node'])->first();

            if ($locked === null || $locked->status !== 'pending') {
                return;
            }

            $instance = WorkflowInstance::where('id', $locked->workflow_instance_id)->lockForUpdate()->firstOrFail();

            if (! in_array($instance->status, self::ActiveStatuses, true)) {
                return;
            }

            $locked->update(['status' => 'timed_out', 'completed_at' => now(), 'sla_breached_at' => $locked->sla_breached_at ?? now()]);

            $this->auditLedger->record(
                instance: $instance,
                eventType: 'step_timed_out',
                payload: ['step_id' => $locked->id, 'due_at' => $locked->due_at?->toIso8601String()],
                step: $locked
            );

            $hasTimeoutPath = WorkflowTransition::where('from_node_id', $locked->workflow_node_id)->whereIn('action_trigger', ['timeout', '*'])->exists();

            if ($hasTimeoutPath && $this->tryAdvance($instance, 'timeout')) {
                return;
            }

            $stillPending = WorkflowInstanceStep::where('workflow_instance_id', $instance->id)
                ->where('workflow_node_id', $locked->workflow_node_id)
                ->where('status', 'pending')
                ->exists();

            if ($stillPending) {
                return;
            }

            if (! $this->resolveNode($instance, $locked)) {
                $this->expire($instance, 'no_decision_in_time');
            }
        });
    }

    /**
     * Moves a pending step to whoever its escalation rule names, with a fresh time limit. A step is escalated once.
     * Returns whether it was escalated.
     */
    public function escalateStep(WorkflowInstanceStep $step, WorkflowEscalationRule $rule): bool
    {
        return DB::transaction(function () use ($step, $rule): bool {
            $locked = WorkflowInstanceStep::where('id', $step->id)->lockForUpdate()->with(['instance', 'node'])->first();

            if ($locked === null || $locked->status !== 'pending' || $locked->escalated_at !== null) {
                return false;
            }

            $instance = $locked->instance;

            if (! in_array($instance->status, self::ActiveStatuses, true)) {
                return false;
            }

            [$userId, $role, $how] = $this->escalationTarget($rule, $locked, $instance);

            $locked->update([
                'assigned_user_id' => $userId,
                'assigned_role' => $userId === null ? $role : null,
                'escalated_at' => now(),
                'escalation_level' => (int) $locked->escalation_level + 1,
                'escalated_to_user_id' => $userId,
                'due_at' => $this->dueAtFor($locked->node),
            ]);

            $this->auditLedger->record(
                instance: $instance,
                eventType: 'step_escalated',
                payload: ['step_id' => $locked->id, 'to_user_id' => $userId, 'to_role' => $userId === null ? $role : null, 'target' => $how, 'rule_id' => $rule->id],
                step: $locked
            );

            $this->dispatchDomainEvent(new WorkflowStepEscalatedEvent(
                instanceId: $instance->id,
                stepId: $locked->id,
                escalatedToUserId: (string) $userId,
                tenantId: $instance->tenant_id,
                companyId: (string) ($instance->company_id ?? $instance->tenant_id)
            ));

            return true;
        });
    }

    /**
     * Records that the reminder time of a pending step has passed. A step is reminded once. Returns whether it was.
     */
    public function remindStep(WorkflowInstanceStep $step): bool
    {
        return DB::transaction(function () use ($step): bool {
            $locked = WorkflowInstanceStep::where('id', $step->id)->lockForUpdate()->with('instance')->first();

            if ($locked === null || $locked->status !== 'pending' || $locked->reminded_at !== null) {
                return false;
            }

            $instance = $locked->instance;

            if (! in_array($instance->status, self::ActiveStatuses, true)) {
                return false;
            }

            $locked->update(['reminded_at' => now()]);

            $this->auditLedger->record($instance, 'step_reminder_sent', ['step_id' => $locked->id, 'assigned_user_id' => $locked->assigned_user_id, 'assigned_role' => $locked->assigned_role], $locked);

            $this->dispatchDomainEvent(new WorkflowStepReminderEvent(
                instanceId: $instance->id,
                stepId: $locked->id,
                assignedUserId: $locked->assigned_user_id,
                assignedRole: $locked->assigned_role,
                dueAt: (string) $locked->due_at?->toIso8601String(),
                tenantId: $instance->tenant_id,
                companyId: (string) ($instance->company_id ?? $instance->tenant_id)
            ));

            return true;
        });
    }

    /**
     * Who the escalation goes to: a named user (if still a member of the company), a role, or the reporting manager of
     * the current assignee (or of the initiator for a role step). Without a usable target the escalation goes to the
     * administrators, so a step never ends up with nobody.
     *
     * @return array{0: ?string, 1: ?string, 2: string}
     */
    private function escalationTarget(WorkflowEscalationRule $rule, WorkflowInstanceStep $step, WorkflowInstance $instance): array
    {
        $tenantId = (string) $instance->tenant_id;

        if ($rule->escalate_to_type === 'specific_user' && $rule->escalate_to_id !== null && $this->assignees->isMember((string) $rule->escalate_to_id, $tenantId)) {
            return [(string) $rule->escalate_to_id, null, 'specific_user'];
        }

        if ($rule->escalate_to_type === 'specific_role' && $rule->escalate_to_id !== null) {
            return [null, (string) $rule->escalate_to_id, 'specific_role'];
        }

        if ($rule->escalate_to_type === 'reporting_manager') {
            $subject = $step->assigned_user_id ?? $instance->created_by;
            $manager = $subject === null ? null : $this->assignees->managerUserIdOf((string) $subject, $tenantId);

            if ($manager !== null) {
                return [$manager, null, 'reporting_manager'];
            }
        }

        return [null, 'admin', 'administrators_fallback'];
    }

    /**
     * Ends an instance nobody decided in time and that has no path for it. The record under approval is told
     * `cancelled`, which sends it back to its state before the approval was requested.
     */
    private function expire(WorkflowInstance $instance, string $reason): void
    {
        $instance->update(['status' => 'expired', 'completed_at' => now()]);

        $this->auditLedger->record($instance, 'instance_expired', ['reason' => $reason]);
        $this->notifyApprovable($instance, 'cancelled');
        $this->dispatchDomainEvent(new WorkflowInstanceExpiredEvent(
            instanceId: $instance->id,
            reason: $reason,
            tenantId: $instance->tenant_id,
            companyId: (string) ($instance->company_id ?? $instance->tenant_id)
        ));
        $this->webhookDispatcher->dispatch(
            tenantId: $instance->tenant_id,
            eventName: 'workflow.expired',
            payload: ['instance_id' => $instance->id, 'status' => 'expired', 'approvable_id' => $instance->approvable_id]
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws ConflictHttpException when the instance already ended: a finished approval cannot be cancelled
     */
    public function cancel(WorkflowInstance $instance, ?string $reason = null): void
    {
        DB::transaction(function () use ($instance, $reason): void {
            $locked = WorkflowInstance::where('id', $instance->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, self::ActiveStatuses, true)) {
                throw new ConflictHttpException("Workflow instance [{$locked->id}] is already {$locked->status} and cannot be cancelled.");
            }

            $locked->update([
                'status' => 'cancelled',
                'completed_at' => now(),
            ]);

            $locked->steps()->where('status', 'pending')->update([
                'status' => 'cancelled',
                'completed_at' => now(),
            ]);

            $this->auditLedger->record(
                instance: $locked,
                eventType: 'instance_cancelled',
                payload: ['reason' => $reason]
            );

            $this->notifyApprovable($locked, 'cancelled');
        });

        $instance->refresh();
    }

    private function createStepsForNode(WorkflowInstance $instance, WorkflowNode $node): void
    {
        $dueAt = $this->dueAtFor($node);

        if (in_array($node->assignee_type, ['matrix_lookup', 'matrix_sequential'], true)) {
            $tiers = $this->matrixEvaluator->tiersFor((string) $instance->tenant_id, (string) $instance->approvable_type, $instance->approvable, $instance->context_snapshot);

            if ($tiers !== null) {
                if ($node->assignee_type === 'matrix_sequential') {
                    $firstLevel = collect($tiers)->min('tier_level');
                    $tiers = collect($tiers)->where('tier_level', $firstLevel)->values()->all();
                }

                $this->createTierSteps($instance, $node, $tiers);

                return;
            }
        }

        if (in_array($node->assignee_type, ['user_list', 'user_list_sequential'], true)) {
            $users = $this->assignees->userList($node->assignee_id);

            if ($users !== []) {
                // Parallel: everybody at once on level 1. Sequential: the first now, the next ones as each approves.
                foreach ($node->assignee_type === 'user_list' ? $users : [$users[0]] as $userId) {
                    $this->createStep($instance, $node, $userId, null, 1, $dueAt);
                }

                return;
            }
        }

        $assignedUserId = $node->assignee_type === 'direct_user' ? $node->assignee_id : null;
        $assignedRole = $node->assignee_type === 'role' ? $node->assignee_id : null;

        if ($node->assignee_type === 'reporting_manager') {
            $manager = $instance->created_by === null ? null : $this->assignees->managerUserIdOf((string) $instance->created_by, (string) $instance->tenant_id);
            $assignedUserId = $manager;
            // Nobody to report to: the administrators decide, rather than leaving a step nobody can see.
            $assignedRole = $manager === null ? 'admin' : null;
        }

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

            // The record decides: the snapshot only fills in fields the record does not have, so a client cannot steer the
            // route of its own approval by sending a lower amount.
            $approvable = $instance->approvable;
            $val = $approvable?->getAttribute($field) ?? $context[$field] ?? null;

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

        // 3. Active delegation (out of office) whose scope covers this kind of approval
        if ($assignedUserId !== '') {
            $approvableType = (string) $step->instance?->approvable_type;
            $delegation = WorkflowDelegation::where('delegator_user_id', $assignedUserId)
                ->where('delegate_user_id', $actingUserId)
                ->where('is_active', true)
                ->where('starts_at', '<=', now())
                ->where('ends_at', '>=', now())
                ->get()
                ->first(static fn (WorkflowDelegation $candidate): bool => $candidate->coversApprovable($approvableType));

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

    /**
     * Roles live only on the membership of the tenant: a global (Spatie) role would let the admin of one company decide
     * the approvals of every other company.
     */
    private function userHasRole(string $userId, string $tenantId, string $role): bool
    {
        $membership = DB::table('tenant_user')
            ->where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->first();

        return $membership !== null && in_array($membership->role, [$role, 'admin', 'owner'], true);
    }

    private function dispatchDomainEvent(object $event): void
    {
        if ($this->eventBus !== null && $event instanceof DomainEventInterface) {
            $this->eventBus->publishAndDispatch($event);
        } else {
            event($event);
        }
    }
}
