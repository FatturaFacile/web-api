<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final class InvoiceGeneratedEvent implements DomainEventInterface
{
    public string $eventId;

    public string $occurredAt;

    public function __construct(
        public string $invoiceId,
        public string $subscriptionId,
        public float $amount,
        public string $currency,
        public string $servicePeriodStart,
        public string $servicePeriodEnd,
        public string $tenantId,
        public string $companyId,
        ?string $eventId = null,
        ?string $occurredAt = null
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid7();
        $this->occurredAt = $occurredAt ?? now()->toIso8601String();
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getAggregateType(): string
    {
        return 'sub_invoice';
    }

    public function getAggregateId(): string
    {
        return $this->invoiceId;
    }

    public function getEventName(): string
    {
        return 'subscription.invoice.generated';
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getOccurredAt(): string
    {
        return $this->occurredAt;
    }

    public function toPayload(): array
    {
        return [
            'invoice_id' => $this->invoiceId,
            'subscription_id' => $this->subscriptionId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'service_period_start' => $this->servicePeriodStart,
            'service_period_end' => $this->servicePeriodEnd,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
