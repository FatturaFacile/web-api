<?php

declare(strict_types=1);

namespace Modules\Procurement\Listeners;

use Modules\Procurement\Events\PurchaseRequisitionCreatedEvent;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;

final class TriggerWorkflowOnRequisitionSubmissionListener
{
    public function __construct(
        private readonly WorkflowEngineInterface $workflowEngine
    ) {}

    public function handle(PurchaseRequisitionCreatedEvent $event): void
    {
        $requisition = PurchaseRequisition::find($event->requisitionId);
        if ($requisition === null || $requisition->status !== 'pending_approval') {
            return;
        }

        try {
            $this->workflowEngine->start(new StartWorkflowDTO(
                workflowCode: 'PURCHASE_REQUISITION_APPROVAL',
                approvable: $requisition,
                initiatorUserId: $requisition->requestor_user_id,
                contextSnapshot: [
                    'total_estimated_amount' => (float) $requisition->total_estimated_amount,
                    'cost_center_id' => $requisition->cost_center_id,
                    'project_id' => $requisition->project_id,
                    'urgency' => $requisition->urgency,
                ],
                tenantId: $requisition->tenant_id,
                companyId: $requisition->company_id
            ));
        } catch (\Throwable) {
            // Workflow definition not configured or handled gracefully
        }
    }
}
