<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Workflow\Contracts\ReactsToApprovalRestart;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Enums\ApprovalOutcome;
use Modules\Workflow\Enums\InstanceStatus;
use Modules\Workflow\Events\WorkflowInstanceBlockedEvent;
use Modules\Workflow\Events\WorkflowInstanceWithdrawnEvent;
use Modules\Workflow\Models\ApprovalDecision;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The parts of an approval's life that are not a decision: the requester withdraws, an administrator cancels with a
 * reason, a node without approver blocks the instance, a document edited after approval restarts it, and steps of an
 * approver who left the company are handed over. Every transition is audited in the hash chain of the instance.
 */
final readonly class WorkflowLifecycleService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(
        private WorkflowAuditLedgerService $auditLedger,
        private ApprovalOutcomeNotifier $notifier,
        private ApprovalGuardService $guard,
        private ApprovableRegistry $registry,
        private WorkflowAssigneeResolver $assignees,
        private WorkflowDelegationResolver $delegations,
        private WorkflowWebhookDispatcherService $webhooks
    ) {}

    /**
     * The requester takes the request back while nobody has decided on it.
     *
     * @throws AuthorizationException when the user is not the requester
     * @throws ConflictHttpException when the instance ended or a decision was already taken
     */
    public function withdraw(WorkflowInstance $instance, string $userId): WorkflowInstance
    {
        return DB::transaction(function () use ($instance, $userId): WorkflowInstance {
            $locked = WorkflowInstance::query()->whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $this->assertActive($locked);

            if ($this->guard->requesterOf($locked) !== $userId) {
                throw new AuthorizationException(__('workflow_errors.lifecycle.withdraw_not_requester'));
            }

            $decided = ApprovalDecision::query()->whereIn('workflow_instance_step_id', $locked->steps()->pluck('id'))->exists();

            if ($decided) {
                throw new ConflictHttpException(__('workflow_errors.lifecycle.withdraw_already_decided'));
            }

            $locked->update(['status' => InstanceStatus::Withdrawn->value, 'withdrawn_at' => now(), 'completed_at' => now()]);
            $locked->steps()->where('status', 'pending')->update(['status' => 'cancelled', 'completed_at' => now()]);

            $this->auditLedger->record($locked, 'instance_withdrawn', ['by' => $userId], null, $userId);
            event(new WorkflowInstanceWithdrawnEvent((string) $locked->id, $userId, (string) $locked->tenant_id, (string) ($locked->company_id ?? $locked->tenant_id)));
            $this->webhooks->dispatch((string) $locked->tenant_id, 'workflow.withdrawn', ['instance_id' => $locked->id, 'status' => 'withdrawn', 'approvable_id' => $locked->approvable_id]);
            $this->notifier->notify($locked, ApprovalOutcome::Withdrawn);

            return $locked;
        });
    }

    /**
     * An administrator ends a running approval; the reason is mandatory and recorded on the instance and in the audit trail.
     *
     * @throws ValidationException when the reason is empty
     */
    public function cancelWithReason(WorkflowInstance $instance, string $reason, string $userId): WorkflowInstance
    {
        if (! in_array($instance->status, InstanceStatus::active(), true)) {
            throw new ConflictHttpException("Workflow instance [{$instance->id}] is already {$instance->status} and cannot be cancelled.");
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('workflow_errors.lifecycle.reason_required')]);
        }

        app(WorkflowEngineInterface::class)->cancel($instance, $reason);

        $instance->refresh();
        $instance->update(['closing_reason' => $reason]);
        $this->auditLedger->record($instance, 'instance_cancelled_by_admin', ['reason' => $reason, 'by' => $userId], null, $userId);

        return $instance;
    }

    /**
     * Stops an instance whose node has nobody to ask. Nothing is approved implicitly: the administrators are alerted
     * through the `workflow.instance.blocked` event and decide (assign an approver or cancel).
     */
    public function block(WorkflowInstance $instance, string $reason): void
    {
        $instance->update(['status' => InstanceStatus::Blocked->value, 'blocked_reason' => $reason, 'blocked_at' => now()]);

        $this->auditLedger->record($instance, 'instance_blocked', ['reason' => $reason]);
        event(new WorkflowInstanceBlockedEvent((string) $instance->id, $reason, (string) $instance->tenant_id, (string) ($instance->company_id ?? $instance->tenant_id)));
    }

    /**
     * After an edit of an approved document: when a key attribute moved beyond the tolerance, the approval is void and a
     * new instance of the same workflow starts. The previous instance keeps its evidence and points to its successor.
     *
     * @return ?WorkflowInstance the new instance, or null when the edit did not touch the approval
     */
    public function invalidateOnEdit(Model $document, ?string $actorId = null): ?WorkflowInstance
    {
        $instance = WorkflowInstance::query()
            ->where('approvable_type', $document::class)
            ->where('approvable_id', (string) $document->getKey())
            ->where('status', InstanceStatus::Approved->value)
            ->whereNotNull('approved_snapshot')
            ->latest('completed_at')
            ->first();

        if ($instance === null) {
            return null;
        }

        $changed = $this->changedKeyFields((array) $instance->approved_snapshot, $this->registry->attributesOf($document));

        if ($changed === []) {
            return null;
        }

        return DB::transaction(function () use ($instance, $document, $actorId, $changed): WorkflowInstance {
            $instance->update(['approved_snapshot' => null, 'closing_reason' => 'invalidated: '.implode(',', $changed)]);
            $this->auditLedger->record($instance, 'approval_invalidated', ['changed_fields' => $changed], null, $actorId);

            $new = app(WorkflowEngineInterface::class)->start(new StartWorkflowDTO(
                workflowCode: (string) $instance->definition?->code,
                approvable: $document,
                contextSnapshot: (array) $instance->context_snapshot,
                tenantId: (string) $instance->tenant_id,
                companyId: $instance->company_id !== null ? (string) $instance->company_id : null,
                initiatorUserId: $actorId ?? ($instance->created_by !== null ? (string) $instance->created_by : null),
            ));

            $new->update(['restarted_from_id' => $instance->id]);

            if ($document instanceof ReactsToApprovalRestart) {
                $document->onApprovalRestarted($new);
            }

            return $new;
        });
    }

    /**
     * Key attributes that differ from the approved snapshot beyond the configured tolerance.
     *
     * @param  array<string, mixed>  $approved
     * @param  array<string, mixed>  $current
     * @return list<string>
     */
    public function changedKeyFields(array $approved, array $current): array
    {
        $tolerance = (float) config('workflow.invalidation.amount_tolerance_percent', 0);
        $changed = [];

        foreach ((array) config('workflow.invalidation.fields', []) as $field) {
            if (! array_key_exists($field, $approved) && ! array_key_exists($field, $current)) {
                continue;
            }

            $before = $approved[$field] ?? null;
            $after = $current[$field] ?? null;

            if ($field === 'amount' && is_numeric($before) && is_numeric($after)) {
                $base = abs((float) $before);
                $delta = abs((float) $after - (float) $before);

                if ($base === 0.0 ? $delta > 0.0 : ($delta / $base * 100) > $tolerance) {
                    $changed[] = $field;
                }

                continue;
            }

            if ((string) $before !== (string) $after) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * Hands over the pending steps of users who are no longer members of the tenant: to the delegate in force, otherwise to
     * the user's manager, otherwise to the administrators. Returns the number of steps moved.
     */
    public function reassignOrphanedSteps(): int
    {
        $moved = 0;

        $steps = WorkflowInstanceStep::query()
            ->where('status', 'pending')
            ->whereNotNull('assigned_user_id')
            ->whereHas('instance', static fn ($query) => $query->whereIn('status', InstanceStatus::active()))
            ->whereNotExists(static fn ($query) => $query->select(DB::raw(1))->from('tenant_user')
                ->whereColumn('tenant_user.user_id', 'workflow_instance_steps.assigned_user_id')
                ->whereColumn('tenant_user.tenant_id', 'workflow_instance_steps.tenant_id'))
            ->with('instance')
            ->get();

        foreach ($steps as $step) {
            $instance = $step->instance;
            $removed = (string) $step->assigned_user_id;
            $tenantId = (string) $step->tenant_id;
            $delegate = $this->delegations->resolve($removed, $tenantId, (string) $instance->approvable_type, $this->guard->amountOf($instance));
            $target = $delegate !== $removed && $this->assignees->isMember($delegate, $tenantId) ? $delegate : $this->assignees->managerUserIdOf($removed, $tenantId);

            $step->update($target !== null
                ? ['assigned_user_id' => $target, 'assigned_role' => null]
                : ['assigned_user_id' => null, 'assigned_role' => 'admin']);

            $this->auditLedger->record($instance, 'step_reassigned_removed_approver', ['step_id' => $step->id, 'from' => $removed, 'to' => $target, 'role' => $target === null ? 'admin' : null], $step, null);
            $moved++;
        }

        return $moved;
    }

    /**
     * @throws ConflictHttpException
     */
    private function assertActive(WorkflowInstance $instance): void
    {
        if (! in_array($instance->status, InstanceStatus::active(), true)) {
            throw new ConflictHttpException(__('workflow_errors.lifecycle.not_active', ['status' => $instance->status]));
        }
    }
}
