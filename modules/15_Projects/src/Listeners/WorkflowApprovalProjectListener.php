<?php

declare(strict_types=1);

namespace Modules\Projects\Listeners;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Modules\Projects\Contracts\ProjectBudgetServiceInterface;
use Modules\Projects\Contracts\ProjectChangeOrderServiceInterface;
use Modules\Projects\Contracts\ProjectExpenseServiceInterface;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBudget;
use Modules\Projects\Models\ProjectChangeOrder;
use Modules\Projects\Models\ProjectCostEntry;
use Modules\Projects\Services\ProjectClosingService;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * Applies the decision of an approval workflow to the project document it was started for: budget, change order,
 * expense or project closure. Only documents still waiting for that decision are touched, so a redelivered event
 * changes nothing. The decision is recorded in the name of the last user who acted on the workflow instance.
 */
final readonly class WorkflowApprovalProjectListener implements ShouldQueueAfterCommit
{
    public function __construct(
        private ProjectBudgetServiceInterface $budgetService,
        private ProjectChangeOrderServiceInterface $changeOrderService,
        private ProjectExpenseServiceInterface $expenseService,
        private ProjectClosingService $closingService,
        private TenantContext $tenantContext,
    ) {}

    public function handleCompleted(WorkflowInstanceCompletedEvent $event): void
    {
        $this->withInstance($event->instanceId, $event->tenantId, function (WorkflowInstance $instance, string $actor): void {
            $target = $this->target($instance);

            match (true) {
                $target instanceof ProjectBudget && $target->status === 'pending_approval' => $this->budgetService->approveBudget($target, $actor),
                $target instanceof ProjectChangeOrder && $target->status === 'pending_approval' => $this->changeOrderService->approveChangeOrder($target, $actor),
                $target instanceof ProjectCostEntry && $target->approval_status === 'pending' => $this->expenseService->approveExpense($target, $actor),
                $target instanceof Project && $target->status === 'pending_closure' => $this->closingService->finalize($target, $actor, 'Closure approved by workflow'),
                default => null,
            };
        });
    }

    public function handleRejected(WorkflowInstanceRejectedEvent $event): void
    {
        $this->withInstance($event->instanceId, $event->tenantId, function (WorkflowInstance $instance, string $actor): void {
            $target = $this->target($instance);

            match (true) {
                $target instanceof ProjectBudget && $target->status === 'pending_approval' => $this->budgetService->rejectBudget($target, $actor, 'Rejected by workflow'),
                $target instanceof ProjectChangeOrder && $target->status === 'pending_approval' => $this->changeOrderService->rejectChangeOrder($target, $actor, 'Rejected by workflow'),
                $target instanceof ProjectCostEntry && $target->approval_status === 'pending' => $this->expenseService->rejectExpense($target, $actor, 'Rejected by workflow'),
                $target instanceof Project && $target->status === 'pending_closure' => $this->closingService->reopenAfterRejectedClosure($target, $actor),
                default => null,
            };
        });
    }

    /**
     * @param  callable(WorkflowInstance, string): void  $callback
     */
    private function withInstance(string $instanceId, string $tenantId, callable $callback): void
    {
        $tenant = $tenantId !== '' ? Tenant::query()->find($tenantId) : null;
        $run = function () use ($instanceId, $callback): void {
            $instance = WorkflowInstance::query()->find($instanceId);

            if (! $instance instanceof WorkflowInstance) {
                return;
            }

            $actor = (string) ($instance->getAttribute('updated_by') ?? $instance->getAttribute('created_by') ?? '');
            $callback($instance, $actor !== '' ? $actor : $this->projectManagerOf($instance));
        };

        $tenant !== null ? $this->tenantContext->runWithTenant($tenant, $run) : $run();
    }

    private function target(WorkflowInstance $instance): ProjectBudget|ProjectChangeOrder|ProjectCostEntry|Project|null
    {
        $type = $instance->getAttribute('approvable_type');
        $id = $instance->getAttribute('approvable_id');

        return match ($type) {
            ProjectBudget::class => ProjectBudget::query()->find($id),
            ProjectChangeOrder::class => ProjectChangeOrder::query()->find($id),
            ProjectCostEntry::class => ProjectCostEntry::query()->find($id),
            Project::class => Project::query()->find($id),
            default => null,
        };
    }

    private function projectManagerOf(WorkflowInstance $instance): string
    {
        $target = $this->target($instance);
        $projectId = $target instanceof Project ? $target->id : $target?->getAttribute('project_id');

        return (string) Project::query()->whereKey($projectId)->value('project_manager_user_id');
    }
}
