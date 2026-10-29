<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final class UsageRecordedEvent implements DomainEventInterface
{
    public string $eventId;

    public string $occurredAt;

    public function __construct(
        public string $usageEventId,
        public string $subscriptionId,
        public string $metricCode,
        public float $quantity,
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
        return 'sub_usage_event';
    }

    public function getAggregateId(): string
    {
        return $this->usageEventId;
    }

    public function getEventName(): string
    {
        return 'subscription.usage.recorded';
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
            'usage_event_id' => $this->usageEventId,
            'subscription_id' => $this->subscriptionId,
            'metric_code' => $this->metricCode,
            'quantity' => $this->quantity,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
