<?php

declare(strict_types=1);

namespace Modules\Quality\Listeners;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Validation\ValidationException;
use Modules\Quality\Models\QmsProcedure;
use Modules\Quality\Services\ProcedureService;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;
use Modules\Workflow\Models\WorkflowInstance;

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
        $this->withProcedure($event->instanceId, $event->tenantId, function (QmsProcedure $procedure, ?string $actor): void {
            $this->procedures->applyWorkflowApproval($procedure, $actor);
        });
    }

    public function handleRejected(WorkflowInstanceRejectedEvent $event): void
    {
        $this->withProcedure($event->instanceId, $event->tenantId, function (QmsProcedure $procedure, ?string $actor): void {
            $this->procedures->applyWorkflowRejection($procedure, 'Rejected by workflow', $actor);
        });
    }

    /**
     * @param  callable(QmsProcedure, string|null): void  $decide
     */
    private function withProcedure(string $instanceId, string $tenantId, callable $decide): void
    {
        $tenant = $tenantId !== '' ? Tenant::query()->find($tenantId) : null;
        $run = function () use ($instanceId, $decide): void {
            $instance = WorkflowInstance::query()->find($instanceId);
            if (! $instance instanceof WorkflowInstance || $instance->getAttribute('approvable_type') !== QmsProcedure::class) {
                return;
            }

            $procedure = QmsProcedure::query()->find($instance->getAttribute('approvable_id'));
            if (! $procedure instanceof QmsProcedure || $procedure->status !== 'pending_approval') {
                return;
            }

            $actor = $instance->getAttribute('updated_by') ?? $instance->getAttribute('created_by');

            try {
                $decide($procedure, $actor !== null ? (string) $actor : null);
            } catch (ValidationException) {
                // The author approved their own procedure through the workflow: it stays pending for another approver.
            }
        };

        $tenant !== null ? $this->tenantContext->runWithTenant($tenant, $run) : $run();
    }
}
