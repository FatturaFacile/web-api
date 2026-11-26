<?php

declare(strict_types=1);

namespace Modules\Workflow\Listeners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Modules\Platform\Services\TenantNotifier;
use Modules\Workflow\Events\WorkflowChangesRequestedEvent;
use Modules\Workflow\Events\WorkflowDelegationActivatedEvent;
use Modules\Workflow\Events\WorkflowInstanceBlockedEvent;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceExpiredEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;
use Modules\Workflow\Events\WorkflowInstanceWithdrawnEvent;
use Modules\Workflow\Events\WorkflowStepAssignedEvent;
use Modules\Workflow\Events\WorkflowStepEscalatedEvent;
use Modules\Workflow\Events\WorkflowStepReminderEvent;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\ApprovableRegistry;
use Modules\Workflow\Services\ApprovalGuardService;
use Throwable;

/**
 * Tells people what the engine does (Mod 008): a new request, a reminder, an escalation, the outcome, an administrator
 * alert for a blocked instance and the activation of a delegation.
 *
 * Data minimisation (GDPR, nLPD): a notification carries the kind of document and its number, never its content, and
 * for leave requests not even that; the person opens the link in the application to see the details. A notification
 * failure never fails the approval it reports.
 */
final readonly class NotifyWorkflowParticipantsListener
{
    private const string Category = 'general';

    /**
     * Inject the collaborators.
     */
    public function __construct(private TenantNotifier $notifier, private ApprovableRegistry $registry, private ApprovalGuardService $guard) {}

    /**
     * A step was assigned: the approver (or the holders of the role) are asked to decide.
     */
    public function handleStepAssigned(WorkflowStepAssignedEvent $event): void
    {
        if ($event->assignedRole === 'system') {
            return;
        }

        $this->safely($event->tenantId, function () use ($event): void {
            $this->send($event->tenantId, $this->approvers($event->tenantId, $event->assignedUserId, $event->assignedRole), 'request', $event->instanceId, ['due' => substr($event->dueAt, 0, 10)]);
        });
    }

    /**
     * The deadline of a step is near: the approver is reminded.
     */
    public function handleReminder(WorkflowStepReminderEvent $event): void
    {
        $this->safely($event->tenantId, function () use ($event): void {
            $this->send($event->tenantId, $this->approvers($event->tenantId, $event->assignedUserId, $event->assignedRole), 'reminder', $event->instanceId, ['due' => substr($event->dueAt, 0, 10)]);
        });
    }

    /**
     * The step went to a higher level.
     */
    public function handleEscalated(WorkflowStepEscalatedEvent $event): void
    {
        $this->safely($event->tenantId, function () use ($event): void {
            $this->send($event->tenantId, [$event->escalatedToUserId], 'escalation', $event->instanceId);
        });
    }

    /**
     * The approval ended: the requester is told the outcome.
     */
    public function handleCompleted(WorkflowInstanceCompletedEvent $event): void
    {
        $this->outcome($event->tenantId, $event->instanceId, $event->status === 'approved' ? 'approved' : 'rejected');
    }

    /**
     * The approval was rejected.
     */
    public function handleRejected(WorkflowInstanceRejectedEvent $event): void
    {
        $this->outcome($event->tenantId, $event->instanceId, 'rejected');
    }

    /**
     * An approver sent the document back.
     */
    public function handleChangesRequested(WorkflowChangesRequestedEvent $event): void
    {
        $this->outcome($event->tenantId, $event->aggregateId, 'changes_requested');
    }

    /**
     * Nobody decided in time and the approval expired.
     */
    public function handleExpired(WorkflowInstanceExpiredEvent $event): void
    {
        $this->outcome($event->tenantId, $event->instanceId, 'expired');
    }

    /**
     * The requester withdrew: the approvers who were asked are told the request is gone.
     */
    public function handleWithdrawn(WorkflowInstanceWithdrawnEvent $event): void
    {
        $this->safely($event->tenantId, function () use ($event): void {
            $recipients = DB::table('workflow_instance_steps')
                ->where('workflow_instance_id', $event->aggregateId)
                ->whereNotNull('assigned_user_id')
                ->pluck('assigned_user_id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->unique()
                ->values()
                ->all();

            $this->send($event->tenantId, $recipients, 'withdrawn', $event->aggregateId);
        });
    }

    /**
     * An instance has no resolvable approver: the owners and administrators are alerted.
     */
    public function handleBlocked(WorkflowInstanceBlockedEvent $event): void
    {
        $this->safely($event->tenantId, function () use ($event): void {
            $this->send($event->tenantId, null, 'blocked', $event->aggregateId);
        });
    }

    /**
     * A delegation was created: the delegate is told they stand in from now on.
     */
    public function handleDelegationActivated(WorkflowDelegationActivatedEvent $event): void
    {
        $this->safely($event->tenantId, function () use ($event): void {
            $this->notifier->notify(
                $event->tenantId,
                self::Category,
                __('workflow_notifications.delegation.title'),
                __('workflow_notifications.delegation.body', ['from' => substr($event->startsAt, 0, 10), 'to' => substr($event->endsAt, 0, 10)]),
                '/workflows/inbox',
                [$event->delegateUserId]
            );
        });
    }

    private function outcome(string $tenantId, string $instanceId, string $key): void
    {
        $this->safely($tenantId, function () use ($tenantId, $instanceId, $key): void {
            $instance = WorkflowInstance::withoutGlobalScopes()->whereKey($instanceId)->first();
            $requester = $instance === null ? null : $this->guard->requesterOf($instance);

            if ($requester !== null) {
                $this->send($tenantId, [$requester], $key, $instanceId);
            }
        });
    }

    /**
     * Users who can decide a step: the named user, or every member holding the role.
     *
     * @return list<string>
     */
    private function approvers(string $tenantId, ?string $userId, ?string $role): array
    {
        if ($userId !== null) {
            return [$userId];
        }

        if ($role === null || $role === '') {
            return [];
        }

        return DB::table('tenant_user')->where('tenant_id', $tenantId)->whereIn('role', array_unique([$role, 'owner', 'admin']))->pluck('user_id')->map(static fn (mixed $id): string => (string) $id)->all();
    }

    /**
     * @param  list<string>|null  $recipients  null = owners and administrators
     * @param  array<string, string>  $replace
     */
    private function send(string $tenantId, ?array $recipients, string $key, string $instanceId, array $replace = []): void
    {
        if ($recipients === []) {
            return;
        }

        $instance = WorkflowInstance::withoutGlobalScopes()->whereKey($instanceId)->first();
        $approvable = $instance?->approvable;
        $kind = $instance === null ? '' : (string) ($this->registry->resolve((string) $instance->approvable_type) ?? 'document');
        // Leave requests are HR data: no number, no detail, only the fact that something waits.
        $label = $kind === 'leave_request' ? '' : $this->registry->labelOf($approvable);

        $this->notifier->notify(
            $tenantId,
            self::Category,
            __('workflow_notifications.'.$key.'.title'),
            __('workflow_notifications.'.$key.'.body', [...$replace, 'type' => __('workflow_notifications.subject.'.(Lang::has('workflow_notifications.subject.'.$kind) ? $kind : 'document')), 'label' => $label]),
            '/workflows/inbox?instance='.$instanceId,
            $recipients
        );
    }

    private function safely(string $tenantId, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
