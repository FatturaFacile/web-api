<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final class RevenueRecognizedEvent implements DomainEventInterface
{
    public string $eventId;

    public string $occurredAt;

    public function __construct(
        public string $recognitionEntryId,
        public string $performanceObligationId,
        public float $amount,
        public string $recognitionDate,
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
        return 'sub_revenue_entry';
    }

    public function getAggregateId(): string
    {
        return $this->recognitionEntryId;
    }

    public function getEventName(): string
    {
        return 'subscription.revenue.recognized';
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
            'recognition_entry_id' => $this->recognitionEntryId,
            'performance_obligation_id' => $this->performanceObligationId,
            'amount' => $this->amount,
            'recognition_date' => $this->recognitionDate,
            'currency' => $this->currency,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
