<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final class MRRForecastEvent implements DomainEventInterface
{
    public string $eventId;

    public string $occurredAt;

    public function __construct(
        public string $targetMonth,
        public float $startingMrr,
        public float $newMrr,
        public float $expansionMrr,
        public float $contractionMrr,
        public float $churnMrr,
        public float $endingMrr,
        public string $currency,
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
        return 'sub_treasury';
    }

    public function getAggregateId(): string
    {
        return $this->eventId;
    }

    public function getEventName(): string
    {
        return 'subscription.treasury.mrr_forecast';
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
            'target_month' => $this->targetMonth,
            'starting_mrr' => $this->startingMrr,
            'new_mrr' => $this->newMrr,
            'expansion_mrr' => $this->expansionMrr,
            'contraction_mrr' => $this->contractionMrr,
            'churn_mrr' => $this->churnMrr,
            'ending_mrr' => $this->endingMrr,
            'currency' => $this->currency,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
