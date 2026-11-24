<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final class RecurringRevenueForecastEvent implements DomainEventInterface
{
    public string $eventId;

    public string $occurredAt;

    public function __construct(
        public string $fiscalQuarter,
        public float $contractedRecurringRevenue,
        public float $estimatedUsageRevenue,
        public float $netExpansionRevenue,
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
        return 'subscription.treasury.recurring_revenue_forecast';
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
            'fiscal_quarter' => $this->fiscalQuarter,
            'contracted_recurring_revenue' => $this->contractedRecurringRevenue,
            'estimated_usage_revenue' => $this->estimatedUsageRevenue,
            'net_expansion_revenue' => $this->netExpansionRevenue,
            'currency' => $this->currency,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
