<?php

declare(strict_types=1);

namespace Modules\Quality\Listeners;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Quality\Models\QmsProcedure;
use Modules\Quality\Services\ProcedureService;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceExpiredEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;
use Modules\Workflow\Models\ApprovalDecision;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngineService;

/**
 * Applies the decision of the approval workflow started for a controlled procedure, in the name of the last user who
 * acted on the workflow instance. Only procedures still pending approval are touched, so a redelivered event changes
 * nothing.
 */
final readonly class WorkflowApprovalQualityListener implements ShouldQueueAfterCommit
{
    public function __construct(private ProcedureService $procedures, private TenantContext $tenantContext) {}

    public function handleCompleted(WorkflowInstanceCompletedEvent $event): void
    {
        $this->withProcedure($event->instanceId, $event->tenantId, 'approved', function (QmsProcedure $procedure, ?string $actor): void {
            // An automatic approval (escalation rule on timeout) never releases a controlled procedure: a person must approve.
            if ($actor === null) {
                return;
            }

            $this->procedures->applyWorkflowApproval($procedure, $actor);
        });
    }

    public function handleRejected(WorkflowInstanceRejectedEvent $event): void
    {
        $this->withProcedure($event->instanceId, $event->tenantId, 'rejected', function (QmsProcedure $procedure, ?string $actor): void {
            $this->procedures->applyWorkflowRejection($procedure, 'Rejected by workflow', $actor);
        });
    }

    public function handleExpired(WorkflowInstanceExpiredEvent $event): void
    {
        $this->withProcedure($event->instanceId, $event->tenantId, 'expired', function (QmsProcedure $procedure, ?string $actor) use ($event): void {
            $this->procedures->applyWorkflowExpiry($procedure, $event->reason);
        });
    }

    /**
     * The person who took the deciding decision of the instance: the latest decision with the outcome, by its effective
     * user. Null when the engine decided by itself (its system actor) or nobody did.
     */
    private function decidingUser(WorkflowInstance $instance, string $outcome): ?string
    {
        $decision = ApprovalDecision::query()
            ->whereIn('workflow_instance_step_id', $instance->steps()->pluck('id'))
            ->where('decision', $outcome)
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->first();

        $user = $decision?->getAttribute('effective_user_id') ?? $decision?->getAttribute('user_id');

        return $user !== null && Str::isUuid((string) $user) && (string) $user !== WorkflowEngineService::SystemActor ? (string) $user : null;
    }

    /**
     * @param  callable(QmsProcedure, string|null): void  $decide
     */
    private function withProcedure(string $instanceId, string $tenantId, string $outcome, callable $decide): void
    {
        $tenant = $tenantId !== '' ? Tenant::query()->find($tenantId) : null;
        $run = function () use ($instanceId, $outcome, $decide): void {
            $instance = WorkflowInstance::query()->find($instanceId);
            if (! $instance instanceof WorkflowInstance || $instance->getAttribute('approvable_type') !== QmsProcedure::class) {
                return;
            }

            $procedure = QmsProcedure::query()->find($instance->getAttribute('approvable_id'));
            if (! $procedure instanceof QmsProcedure || $procedure->status !== 'pending_approval') {
                return;
            }

            $actor = $this->decidingUser($instance, $outcome);

            try {
                $decide($procedure, $actor);
            } catch (ValidationException) {
                // The author approved their own procedure through the workflow: it stays pending for another approver.
            }
        };

        $tenant !== null ? $this->tenantContext->runWithTenant($tenant, $run) : $run();
    }
}
