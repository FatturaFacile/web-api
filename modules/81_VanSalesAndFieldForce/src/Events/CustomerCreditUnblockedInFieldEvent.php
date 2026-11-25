<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;

final readonly class CustomerCreditUnblockedInFieldEvent implements DomainEventInterface
{
    public function __construct(
        private string $paymentId,
        private string $tenantId,
        private string $contactId,
        private string $invoiceId,
        private float $collectedAmount,
        private string $paymentMethod,
    ) {}

    public function getEventName(): string
    {
        return 'van_sales.customer_credit_unblocked';
    }

    public function getAggregateType(): string
    {
        return 'contact';
    }

    public function getAggregateId(): string
    {
        return $this->contactId;
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->tenantId;
    }

    public function toPayload(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'contact_id' => $this->contactId,
            'invoice_id' => $this->invoiceId,
            'collected_amount' => $this->collectedAmount,
            'payment_method' => $this->paymentMethod,
        ];
    }
}
