<?php

declare(strict_types=1);

namespace Modules\Treasury\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Modules\Treasury\Contracts\PaymentFactoryServiceInterface;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

final readonly class WorkflowApprovalTreasuryListener
{
    public function __construct(
        private PaymentFactoryServiceInterface $paymentFactory,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function handleWorkflowApproved(WorkflowInstanceCompletedEvent $event): void
    {
        $batch = PaymentBatch::where('workflow_instance_id', $event->instanceId)->first();
        if (! $batch || ! in_array(strtoupper((string) $batch->status), ['PENDING_APPROVAL', 'PENDING'])) {
            return;
        }

        $batch->update([
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $this->auditLedger->record(
            action: 'payment_batch_workflow_approved',
            model: $batch,
            payloadBefore: null,
            payloadAfter: $batch->toArray(),
            moduleCode: 'MODULE_24_TREASURY'
        );
    }

    public function handleWorkflowRejected(WorkflowInstanceRejectedEvent $event): void
    {
        $batch = PaymentBatch::where('workflow_instance_id', $event->instanceId)->first();
        if (! $batch || ! in_array(strtoupper((string) $batch->status), ['PENDING_APPROVAL', 'PENDING'])) {
            return;
        }

        $this->paymentFactory->cancelBatch(
            (string) $batch->id,
            "Rejected by workflow engine instance {$event->instanceId}: {$event->reason}"
        );
    }
}
