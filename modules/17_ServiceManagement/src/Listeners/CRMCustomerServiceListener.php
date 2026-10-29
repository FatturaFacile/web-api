<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Models\TransactionalInbox;
use Illuminate\Support\Str;
use Modules\CRM\Events\OpportunityWonEvent;
use Modules\Invoicing\Events\SalesQuoteAcceptedEvent;
use Modules\ServiceManagement\Models\ServiceContract;

final readonly class CRMCustomerServiceListener
{
    public function __construct(
        private AuditLedgerInterface $auditLedger
    ) {}

    public function handleOpportunityWon(OpportunityWonEvent $event): void
    {
        if (! $this->recordInboxIfNew($event, 'CRMCustomerServiceListener::handleOpportunityWon')) {
            return;
        }

        $contractNumber = 'SC-'.date('Ymd').'-'.strtoupper(Str::random(6));

        $contract = ServiceContract::create([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'contract_number' => $contractNumber,
            'title' => 'Service Agreement - Deal '.substr($event->opportunityId, 0, 8),
            'customer_id' => $event->accountId,
            'status' => 'draft',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'annual_value' => $event->amount,
            'currency' => $event->currency,
            'auto_renew' => true,
        ]);

        $this->auditLedger->record(
            'ESM_SERVICE_CONTRACT_CREATED_FROM_CRM',
            $contract,
            null,
            ['opportunity_id' => $event->opportunityId, 'amount' => $event->amount],
            'MODULE_17_ESM'
        );
    }

    public function handleSalesQuoteAccepted(SalesQuoteAcceptedEvent $event): void
    {
        if (! $this->recordInboxIfNew($event, 'CRMCustomerServiceListener::handleSalesQuoteAccepted')) {
            return;
        }

        $contractNumber = 'SC-'.date('Ymd').'-'.strtoupper(Str::random(6));

        $contract = ServiceContract::create([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'contract_number' => $contractNumber,
            'title' => 'Service Agreement - Quote '.$event->quoteNumber,
            'customer_id' => $event->customerId,
            'status' => 'active',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'annual_value' => $event->totalAmount,
            'currency' => 'EUR',
            'auto_renew' => true,
        ]);

        $this->auditLedger->record(
            'ESM_SERVICE_CONTRACT_CREATED_FROM_SALES_QUOTE',
            $contract,
            null,
            ['quote_id' => $event->salesQuoteId, 'amount' => $event->totalAmount],
            'MODULE_17_ESM'
        );
    }

    private function recordInboxIfNew(DomainEventInterface $event, string $consumerName): bool
    {
        $messageId = $event->getEventId();
        if (TransactionalInbox::where('message_id', $messageId)->exists()) {
            return false;
        }

        TransactionalInbox::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $event->getTenantId() ?: $event->getCompanyId(),
            'message_id' => $messageId,
            'event_type' => $event->getEventName().':'.$consumerName,
            'payload_json' => $event->toPayload(),
            'status' => 'completed',
            'received_at' => now(),
            'processed_at' => now(),
        ]);

        return true;
    }
}
