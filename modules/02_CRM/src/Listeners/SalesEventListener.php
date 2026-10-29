<?php

declare(strict_types=1);

namespace Modules\CRM\Listeners;

use Modules\CRM\Contracts\CustomerHealthServiceInterface;
use Modules\CRM\Contracts\OpportunityServiceInterface;
use Modules\CRM\DTOs\EvaluateHealthDTO;
use Modules\CRM\DTOs\UpdateOpportunityStageDTO;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Opportunity;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\Invoicing\Events\SalesOrderCreatedEvent;
use Modules\Invoicing\Events\SalesQuoteAcceptedEvent;

final readonly class SalesEventListener
{
    public function __construct(
        private OpportunityServiceInterface $opportunityService,
        private CustomerHealthServiceInterface $healthService
    ) {}

    public function handleQuoteAccepted(SalesQuoteAcceptedEvent $event): void
    {
        $opportunity = Opportunity::where('sales_quote_id', $event->quoteId)->first();
        if ($opportunity !== null && $opportunity->status !== 'won') {
            $this->opportunityService->changeStage(
                $opportunity,
                new UpdateOpportunityStageDTO(
                    targetStageCode: 'closed_won',
                    amount: (float) $event->totalGross,
                    probability: 100.0,
                    actualCloseDate: date('Y-m-d')
                ),
                actorUserId: $event->userId
            );
        }
    }

    public function handleSalesOrderCreated(SalesOrderCreatedEvent $event): void
    {
        // When a sales order is created from CRM quote or counterparty, ensure account status is updated to customer
        $account = Account::where('tenant_id', $event->tenantId)
            ->where('contact_id', $event->customerId)
            ->first();

        if ($account !== null && $account->lifecycle_stage !== 'customer') {
            $account->update(['lifecycle_stage' => 'customer']);
        }
    }

    public function handleInvoicePosted(InvoicePostedEvent $event): void
    {
        // When invoice is posted, recalculate customer health metrics with new revenue and open receivable
        $account = Account::where('tenant_id', $event->tenantId)
            ->where('contact_id', $event->customerId)
            ->first();

        if ($account !== null) {
            $this->healthService->evaluateHealth(
                $account,
                new EvaluateHealthDTO(
                    accountId: (string) $account->id,
                    openReceivableAmount: (float) $event->totalGross,
                    daysSinceLastInteraction: 0
                )
            );
        }
    }
}
