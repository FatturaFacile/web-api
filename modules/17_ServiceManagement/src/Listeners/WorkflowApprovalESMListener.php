<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Modules\ServiceManagement\Contracts\ProblemAndChangeServiceInterface;
use Modules\ServiceManagement\Models\ChangeRequest;
use Modules\ServiceManagement\Models\Incident;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\WorkOrder;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

final readonly class WorkflowApprovalESMListener
{
    public function __construct(
        private ProblemAndChangeServiceInterface $changeService,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function handleWorkflowApproved(WorkflowInstanceCompletedEvent $event): void
    {
        $instanceId = $event->instanceId;

        $change = ChangeRequest::where('workflow_instance_id', $instanceId)->first();
        if ($change !== null && in_array($change->status, ['submitted', 'in_review'], true)) {
            $change->update(['is_locked' => false]);
            $this->changeService->approveChangeRequest($change->id, 'WorkflowEngine');

            return;
        }

        $workOrder = WorkOrder::where('workflow_instance_id', $instanceId)->first();
        if ($workOrder !== null) {
            $workOrder->update([
                'status' => 'approved',
                'is_locked' => false,
            ]);
            $this->auditLedger->record('ESM_WORK_ORDER_WORKFLOW_APPROVED', $workOrder, null, ['instance_id' => $instanceId], 'MODULE_17_ESM');

            return;
        }

        $ticket = Ticket::where('workflow_instance_id', $instanceId)->first();
        if ($ticket !== null) {
            $ticket->update(['is_locked' => false]);
            $this->auditLedger->record('ESM_TICKET_WORKFLOW_APPROVED', $ticket, null, ['instance_id' => $instanceId], 'MODULE_17_ESM');

            return;
        }

        $contract = ServiceContract::where('workflow_instance_id', $instanceId)->first();
        if ($contract !== null) {
            $contract->update([
                'status' => 'active',
                'is_locked' => false,
            ]);
            $this->auditLedger->record('ESM_SERVICE_CONTRACT_WORKFLOW_APPROVED', $contract, null, ['instance_id' => $instanceId], 'MODULE_17_ESM');

            return;
        }

        $incident = Incident::where('workflow_instance_id', $instanceId)->first();
        if ($incident !== null) {
            $incident->update(['is_locked' => false]);
            $this->auditLedger->record('ESM_INCIDENT_WORKFLOW_APPROVED', $incident, null, ['instance_id' => $instanceId], 'MODULE_17_ESM');
        }
    }

    public function handleWorkflowRejected(WorkflowInstanceRejectedEvent $event): void
    {
        $instanceId = $event->instanceId;

        $change = ChangeRequest::where('workflow_instance_id', $instanceId)->first();
        if ($change !== null) {
            $change->update([
                'status' => 'rejected',
                'is_locked' => false,
            ]);
            $this->auditLedger->record('ESM_CHANGE_REQUEST_WORKFLOW_REJECTED', $change, null, ['instance_id' => $instanceId], 'MODULE_17_ESM');

            return;
        }

        $workOrder = WorkOrder::where('workflow_instance_id', $instanceId)->first();
        if ($workOrder !== null) {
            $workOrder->update([
                'status' => 'cancelled',
                'is_locked' => false,
            ]);
            $this->auditLedger->record('ESM_WORK_ORDER_WORKFLOW_REJECTED', $workOrder, null, ['instance_id' => $instanceId], 'MODULE_17_ESM');

            return;
        }

        $ticket = Ticket::where('workflow_instance_id', $instanceId)->first();
        if ($ticket !== null) {
            $ticket->update(['is_locked' => false]);
            $this->auditLedger->record('ESM_TICKET_WORKFLOW_REJECTED', $ticket, null, ['instance_id' => $instanceId], 'MODULE_17_ESM');

            return;
        }

        $contract = ServiceContract::where('workflow_instance_id', $instanceId)->first();
        if ($contract !== null) {
            $contract->update([
                'status' => 'suspended',
                'is_locked' => false,
            ]);
            $this->auditLedger->record('ESM_SERVICE_CONTRACT_WORKFLOW_REJECTED', $contract, null, ['instance_id' => $instanceId], 'MODULE_17_ESM');
        }
    }
}
