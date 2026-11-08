<?php

declare(strict_types=1);

namespace Modules\Procurement\Listeners;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\ThreeWayMatchLog;
use Modules\Procurement\Services\ProcurementApprovalService;
use Modules\Procurement\Services\ThreeWayMatchingService;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * Applies the end of a workflow to its procurement document when the engine did not already do it in the decision
 * (instances written by other paths): orders and requisitions waiting for approval are approved or rejected, a match
 * discrepancy approved through a workflow releases the supplier invoice. An approved order or requisition is unlocked
 * again after the fiscal lock of the workflow.
 */
final readonly class WorkflowApprovalProcurementListener implements ShouldQueueAfterCommit
{
    public function __construct(
        private ProcurementApprovalService $approvals,
        private ThreeWayMatchingService $matching,
    ) {}

    public function handleCompleted(WorkflowInstanceCompletedEvent $event): void
    {
        $this->apply($event->instanceId, 'approved');
    }

    public function handleRejected(WorkflowInstanceRejectedEvent $event): void
    {
        $this->apply($event->instanceId, 'rejected');
    }

    private function apply(string $instanceId, string $outcome): void
    {
        $instance = WorkflowInstance::find($instanceId);
        if ($instance === null) {
            return;
        }

        $document = match ($instance->subject_type) {
            PurchaseOrder::class => PurchaseOrder::find($instance->subject_id),
            PurchaseRequisition::class => PurchaseRequisition::find($instance->subject_id),
            ThreeWayMatchLog::class => ThreeWayMatchLog::find($instance->subject_id),
            default => null,
        };

        if ($document instanceof ThreeWayMatchLog) {
            if ($outcome === 'approved' && in_array($document->match_status, ['price_variance', 'quantity_variance', 'escalated_to_workflow'], true)) {
                $this->matching->approveVariance($document, User::find($instance->assigned_to_user_id), 'Approved through workflow');
            } elseif ($outcome === 'rejected') {
                $document->update(['match_status' => 'rejected']);
            }

            return;
        }

        if ($document instanceof PurchaseOrder || $document instanceof PurchaseRequisition) {
            $this->approvals->applyOutcome($document, $instance, $outcome);

            // The fiscal lock of the workflow freezes documents; an approved order or requisition must stay workable.
            if ($document->status === 'approved' && $document->is_locked) {
                $document->update(['is_locked' => false]);
            }
        }
    }
}
