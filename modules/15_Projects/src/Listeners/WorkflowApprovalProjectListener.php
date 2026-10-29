<?php

declare(strict_types=1);

namespace Modules\Projects\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Projects\Contracts\ProjectBudgetServiceInterface;
use Modules\Projects\Contracts\ProjectChangeOrderServiceInterface;
use Modules\Projects\Events\ProjectClosedEvent;
use Modules\Projects\Events\ProjectClosureReadyEvent;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBudget;
use Modules\Projects\Models\ProjectChangeOrder;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;
use Modules\Workflow\Models\WorkflowInstance;

final readonly class WorkflowApprovalProjectListener implements ShouldQueue
{
    public function __construct(
        private ProjectBudgetServiceInterface $budgetService,
        private ProjectChangeOrderServiceInterface $changeOrderService
    ) {}

    public function handleCompleted(WorkflowInstanceCompletedEvent $event): void
    {
        $instance = WorkflowInstance::find($event->instanceId);
        if (! $instance) {
            return;
        }

        $targetType = $instance->approvable_type ?? $instance->subject_type ?? null;
        $targetId = $instance->approvable_id ?? $instance->subject_id ?? null;

        if ($targetType === ProjectBudget::class) {
            $budget = ProjectBudget::find($targetId);
            if ($budget) {
                $this->budgetService->baselineBudget($budget, (string) $instance->initiator_user_id);
            }
        } elseif ($targetType === ProjectChangeOrder::class) {
            $changeOrder = ProjectChangeOrder::find($targetId);
            if ($changeOrder) {
                $this->changeOrderService->approveChangeOrder($changeOrder, (string) $instance->initiator_user_id);
            }
        } elseif ($targetType === Project::class) {
            $project = Project::with('financialSummary')->find($targetId);
            if ($project) {
                $project->update([
                    'status' => 'closed',
                    'actual_end_date' => now()->toDateString(),
                ]);

                // Emit ProjectClosedEvent and ProjectClosureReadyEvent
                $summary = $project->financialSummary;
                $finalCost = (float) ($summary?->total_actual_cost ?? 0.0);
                $finalRevenue = (float) $project->contract_value;
                $retainedEarnings = $finalRevenue - $finalCost;

                event(new ProjectClosedEvent(
                    projectId: (string) $project->id,
                    projectNumber: (string) $project->project_number,
                    closedBy: (string) $instance->initiator_user_id,
                    closureReason: 'Formal workflow closure approved',
                    tenantId: (string) $project->tenant_id,
                    companyId: (string) ($project->company_id ?? $project->tenant_id)
                ));

                event(new ProjectClosureReadyEvent(
                    projectId: (string) $project->id,
                    finalContractValue: $finalRevenue,
                    finalCost: $finalCost,
                    unbilledWipBalance: 0.00,
                    retainedEarningsImpact: $retainedEarnings,
                    fiscalYear: now()->format('Y'),
                    tenantId: (string) $project->tenant_id,
                    companyId: (string) ($project->company_id ?? $project->tenant_id)
                ));
            }
        }
    }

    public function handleRejected(WorkflowInstanceRejectedEvent $event): void
    {
        $instance = WorkflowInstance::find($event->instanceId);
        if (! $instance) {
            return;
        }

        $targetType = $instance->approvable_type ?? $instance->subject_type ?? null;
        $targetId = $instance->approvable_id ?? $instance->subject_id ?? null;

        if ($targetType === ProjectBudget::class) {
            $budget = ProjectBudget::find($targetId);
            if ($budget) {
                $budget->update(['status' => 'superseded']);
            }
        } elseif ($targetType === ProjectChangeOrder::class) {
            $changeOrder = ProjectChangeOrder::find($targetId);
            if ($changeOrder) {
                $changeOrder->update(['status' => 'rejected']);
            }
        } elseif ($targetType === Project::class) {
            $project = Project::find($targetId);
            if ($project) {
                $project->update(['status' => 'on_hold']);
            }
        }
    }
}
