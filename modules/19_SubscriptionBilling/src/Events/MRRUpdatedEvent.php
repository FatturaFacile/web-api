<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final class MRRUpdatedEvent implements DomainEventInterface
{
    public string $eventId;

    public string $occurredAt;

    public function __construct(
        public string $subscriptionId,
        public float $oldMrr,
        public float $newMrr,
        public float $mrrDelta,
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
        return 'subscription.mrr.updated';
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
            'old_mrr' => $this->oldMrr,
            'new_mrr' => $this->newMrr,
            'mrr_delta' => $this->mrrDelta,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
