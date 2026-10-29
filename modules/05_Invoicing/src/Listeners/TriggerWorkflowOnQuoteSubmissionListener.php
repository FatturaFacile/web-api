<?php

declare(strict_types=1);

namespace Modules\Invoicing\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Modules\Invoicing\Events\SalesQuoteSubmittedEvent;
use Modules\Invoicing\Models\SalesQuote;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;

final class TriggerWorkflowOnQuoteSubmissionListener
{
    public function __construct(
        private readonly WorkflowEngineInterface $workflowEngine,
        private readonly ?AuditLedgerInterface $auditLedger = null
    ) {}

    public function handle(SalesQuoteSubmittedEvent $event): void
    {
        $quote = SalesQuote::find($event->quoteId);
        if ($quote === null || $quote->status !== 'pending_approval') {
            return;
        }

        $workflowCode = (float) $event->totalGross > 25000.0
            ? 'HIGH_VALUE_SALES_QUOTE_APPROVAL'
            : 'SALES_QUOTE_APPROVAL';

        try {
            $instance = $this->workflowEngine->start(new StartWorkflowDTO(
                workflowCode: $workflowCode,
                approvable: $quote,
                contextSnapshot: [
                    'quote_number' => $event->quoteNumber,
                    'customer_id' => $event->customerId,
                    'total_gross' => (float) $event->totalGross,
                    'currency' => $event->currency,
                ],
                tenantId: $event->tenantId,
                companyId: $event->companyId,
                initiatorUserId: $event->userId
            ));

            $quote->update([
                'workflow_instance_id' => (string) $instance->id,
            ]);

            $this->auditLedger?->record(
                action: 'SALES_QUOTE_WORKFLOW_TRIGGERED',
                model: $quote,
                payloadBefore: null,
                payloadAfter: [
                    'quote_id' => (string) $quote->id,
                    'quote_number' => $event->quoteNumber,
                    'workflow_instance_id' => (string) $instance->id,
                    'workflow_code' => $workflowCode,
                ],
                moduleCode: 'MODULE_05_INVOICING'
            );
        } catch (\Throwable) {
            // Graceful degradation when workflow definition is not initialized
        }
    }
}
