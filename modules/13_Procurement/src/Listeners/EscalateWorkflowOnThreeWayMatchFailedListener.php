<?php

declare(strict_types=1);

namespace Modules\Procurement\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;
use Modules\Procurement\Events\ThreeWayMatchDiscrepancyDetectedEvent;
use Modules\Procurement\Models\ThreeWayMatchLog;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Throwable;

final readonly class EscalateWorkflowOnThreeWayMatchFailedListener implements ShouldQueueAfterCommit
{
    public const string WORKFLOW_CODE = 'THREE_WAY_MATCH_DISCREPANCY_APPROVAL';

    public function __construct(
        private WorkflowEngineInterface $workflowEngine,
        private ?AuditLedgerInterface $auditLedger = null
    ) {}

    public function handle(ThreeWayMatchDiscrepancyDetectedEvent $event): void
    {
        $matchLog = ThreeWayMatchLog::find($event->matchLogId);
        if ($matchLog === null) {
            return;
        }

        try {
            $this->workflowEngine->start(new StartWorkflowDTO(
                workflowCode: self::WORKFLOW_CODE,
                approvable: $matchLog,
                contextSnapshot: [
                    'purchase_order_id' => $event->purchaseOrderId,
                    'supplier_id' => $event->supplierId,
                    'po_amount' => $event->poAmount,
                    'invoice_amount' => $event->invoiceAmount,
                    'price_variance' => $event->priceVariance,
                    'quantity_variance' => $event->quantityVariance,
                ],
                tenantId: (string) $matchLog->tenant_id,
                companyId: $event->companyId !== '' ? $event->companyId : null,
            ));
        } catch (Throwable $exception) {
            // Without an active definition the discrepancy stays visible for manual review.
            Log::warning('Three-way match discrepancy not escalated to a workflow.', [
                'match_log_id' => (string) $matchLog->id,
                'workflow_code' => self::WORKFLOW_CODE,
                'reason' => $exception->getMessage(),
            ]);

            return;
        }

        $matchLog->update(['match_status' => 'escalated_to_workflow']);

        $this->auditLedger?->record(
            action: 'THREE_WAY_MATCH_ESCALATED_TO_WORKFLOW',
            model: $matchLog,
            payloadBefore: ['match_status' => $event->priceVariance > 0.0 ? 'price_variance' : 'quantity_variance'],
            payloadAfter: ['match_status' => 'escalated_to_workflow'],
            moduleCode: 'PROCUREMENT'
        );
    }
}
