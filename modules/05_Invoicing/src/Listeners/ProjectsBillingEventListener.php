<?php

declare(strict_types=1);

namespace Modules\Invoicing\Listeners;

use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Projects\Events\ProjectBillingReadyEvent;

final readonly class ProjectsBillingEventListener
{
    public function __construct(
        private InvoicingServiceInterface $invoicingService
    ) {}

    public function handle(ProjectBillingReadyEvent $event): void
    {
        $this->invoicingService->createInvoiceFromMilestone(
            projectId: $event->projectId,
            milestoneId: (string) ($event->milestoneId ?? $event->billingEventId),
            customerId: $event->contactId,
            amount: $event->billingAmount,
            description: 'Project Billing - '.$event->billingType,
            tenantId: $event->tenantId
        );
    }
}
