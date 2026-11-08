<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Modules\AssetManagement\Contracts\AssetLifecycleServiceInterface;
use Modules\AssetManagement\Models\MaintenanceWorkOrder;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

/**
 * Applies or discards the asset operations waiting for an approval workflow: capitalization, transfer, valuation and
 * disposal take effect only on approval; a large maintenance work order is released or cancelled.
 */
final readonly class WorkflowApprovalAssetListener
{
    public function __construct(
        private AuditLedgerInterface $auditLedger,
        private AssetLifecycleServiceInterface $lifecycle,
    ) {}

    /**
     * Applies the approved operation.
     */
    public function handleWorkflowApproved(WorkflowInstanceCompletedEvent $event): void
    {
        if ($this->lifecycle->approveWorkflowSubject($event->instanceId)) {
            return;
        }

        $workOrder = MaintenanceWorkOrder::where('workflow_instance_id', $event->instanceId)->first();
        if ($workOrder !== null) {
            $workOrder->update(['status' => 'scheduled', 'is_locked' => false, 'approval_status' => 'approved']);
            $this->auditLedger->record('MAINTENANCE_WORK_ORDER_WORKFLOW_APPROVED', $workOrder, null, $workOrder->toArray(), 'MODULE_16_EAM');
        }
    }

    /**
     * Discards the rejected operation.
     */
    public function handleWorkflowRejected(WorkflowInstanceRejectedEvent $event): void
    {
        if ($this->lifecycle->rejectWorkflowSubject($event->instanceId)) {
            return;
        }

        $workOrder = MaintenanceWorkOrder::where('workflow_instance_id', $event->instanceId)->first();
        if ($workOrder !== null) {
            $workOrder->update(['status' => 'cancelled', 'is_locked' => false, 'approval_status' => 'rejected', 'cancelled_at' => now()]);
            $this->auditLedger->record('MAINTENANCE_WORK_ORDER_WORKFLOW_REJECTED', $workOrder, null, $workOrder->toArray(), 'MODULE_16_EAM');
        }
    }
}
