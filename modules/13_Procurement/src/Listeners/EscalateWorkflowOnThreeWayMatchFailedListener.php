<?php

declare(strict_types=1);

namespace Modules\Procurement\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Procurement\Events\ThreeWayMatchDiscrepancyDetectedEvent;
use Modules\Procurement\Models\ThreeWayMatchLog;
use Modules\Workflow\Contracts\WorkflowEngineServiceInterface;

final readonly class EscalateWorkflowOnThreeWayMatchFailedListener implements ShouldQueue
{
    public function __construct(
        private WorkflowEngineServiceInterface $workflowEngine,
        private ?AuditLedgerInterface $auditLedger = null
    ) {}

    public function handle(ThreeWayMatchDiscrepancyDetectedEvent $event): void
    {
        $matchLog = ThreeWayMatchLog::find($event->matchLogId);
        if ($matchLog === null) {
            return;
        }

        $matchLog->update(['status' => 'escalated_to_workflow']);

        $this->workflowEngine->startWorkflow(
            definitionCode: 'THREE_WAY_MATCH_DISCREPANCY_APPROVAL',
            subjectType: ThreeWayMatchLog::class,
            subjectId: (string) $matchLog->id,
            companyId: (string) ($event->companyId !== '' ? $event->companyId : 'default'),
            requesterId: 'system',
            payload: [
                'purchase_order_id' => $event->purchaseOrderId,
                'supplier_id' => $event->supplierId,
                'po_amount' => $event->poAmount,
                'invoice_amount' => $event->invoiceAmount,
                'price_variance' => $event->priceVariance,
                'quantity_variance' => $event->quantityVariance,
            ]
        );

        $this->auditLedger?->record(
            action: 'THREE_WAY_MATCH_ESCALATED_TO_WORKFLOW',
            model: $matchLog,
            payloadBefore: ['status' => 'discrepancy'],
            payloadAfter: ['status' => 'escalated_to_workflow'],
            moduleCode: 'PROCUREMENT'
        );
    }
}
