<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final class SubscriptionRenewedEvent implements DomainEventInterface
{
    public string $eventId;

    public string $occurredAt;

    public function __construct(
        public string $subscriptionId,
        public string $subscriptionNumber,
        public string $newPeriodStart,
        public string $newPeriodEnd,
        public float $renewalAmount,
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
        return 'sub_subscription';
    }

    public function getAggregateId(): string
    {
        return $this->subscriptionId;
    }

    public function getEventName(): string
    {
        return 'subscription.renewed';
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
            'subscription_id' => $this->subscriptionId,
            'subscription_number' => $this->subscriptionNumber,
            'new_period_start' => $this->newPeriodStart,
            'new_period_end' => $this->newPeriodEnd,
            'renewal_amount' => $this->renewalAmount,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
