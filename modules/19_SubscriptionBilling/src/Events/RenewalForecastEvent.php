<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final class RenewalForecastEvent implements DomainEventInterface
{
    public string $eventId;

    public string $occurredAt;

    public function __construct(
        public string $forecastPeriod,
        public int $projectedRenewalsCount,
        public float $projectedRenewalValue,
        public float $renewalProbabilityRate,
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
        return 'subscription.treasury.renewal_forecast';
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
            'forecast_period' => $this->forecastPeriod,
            'projected_renewals_count' => $this->projectedRenewalsCount,
            'projected_renewal_value' => $this->projectedRenewalValue,
            'renewal_probability_rate' => $this->renewalProbabilityRate,
            'currency' => $this->currency,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
