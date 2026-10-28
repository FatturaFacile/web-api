<?php

declare(strict_types=1);

namespace Modules\Procurement\Listeners;

use Modules\Procurement\Events\PurchaseOrderCreatedEvent;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;

final class TriggerWorkflowOnPurchaseOrderSubmissionListener
{
    public function __construct(
        private readonly WorkflowEngineInterface $workflowEngine
    ) {}

    public function handle(PurchaseOrderCreatedEvent $event): void
    {
        $order = PurchaseOrder::find($event->purchaseOrderId);
        if ($order === null || $order->status !== 'pending_approval') {
            return;
        }

        $workflowCode = (float) $order->total_amount > 50000.0
            ? 'HIGH_VALUE_PURCHASE_APPROVAL'
            : 'PURCHASE_ORDER_APPROVAL';

        try {
            $this->workflowEngine->start(new StartWorkflowDTO(
                workflowCode: $workflowCode,
                approvable: $order,
                initiatorUserId: $order->created_by ?? '00000000-0000-0000-0000-000000000000',
                contextSnapshot: [
                    'total_amount' => (float) $order->total_amount,
                    'supplier_id' => $order->supplier_id,
                    'cost_center_id' => $order->cost_center_id,
                    'project_id' => $order->project_id,
                ],
                tenantId: $order->tenant_id,
                companyId: $order->company_id
            ));
        } catch (\Throwable) {
            // Workflow definition not configured or handled gracefully
        }
    }
}
