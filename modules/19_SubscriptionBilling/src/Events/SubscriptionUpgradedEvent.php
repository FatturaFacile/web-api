<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final class SubscriptionUpgradedEvent implements DomainEventInterface
{
    public string $eventId;

    public string $occurredAt;

    public function __construct(
        public string $subscriptionId,
        public string $oldPlanId,
        public string $newPlanId,
        public float $mrrDelta,
        public float $prorationAmount,
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
        return 'subscription.upgraded';
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
            'old_plan_id' => $this->oldPlanId,
            'new_plan_id' => $this->newPlanId,
            'mrr_delta' => $this->mrrDelta,
            'proration_amount' => $this->prorationAmount,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
