<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final class SubscriptionCashForecastEvent implements DomainEventInterface
{
    public string $eventId;

    public string $occurredAt;

    public function __construct(
        public string $forecastPeriod,
        public float $projectedCashInflow,
        public string $currency,
        public string $tenantId,
        public string $companyId,
        public array $breakdown = [],
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
        return 'subscription.treasury.cash_forecast';
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
            'projected_cash_inflow' => $this->projectedCashInflow,
            'currency' => $this->currency,
            'breakdown' => $this->breakdown,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
