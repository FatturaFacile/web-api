<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * Monthly projection of the production cost of open and planned orders, at standard cost, for Treasury.
 */
final class ProductionCashForecastEvent extends BaseManufacturingEvent
{
    public function __construct(
        public array $monthlyForecast,
        public string $currency,
        string $tenantId,
        ?string $companyId = null,
        ?string $eventId = null,
        ?string $occurredAt = null
    ) {
        parent::__construct($tenantId, $companyId, $eventId, $occurredAt);
    }

    /**
     * Aggregate type of the event.
     */
    public function getAggregateType(): string
    {
        return 'mfg_treasury_forecast';
    }

    /**
     * Aggregate id of the event.
     */
    public function getAggregateId(): string
    {
        return $this->tenantId;
    }

    /**
     * Name of the event in the outbox.
     */
    public function getEventName(): string
    {
        return 'mfg.treasury.production_cash_forecast';
    }

    /**
     * Payload of the event.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'monthly_forecast' => $this->monthlyForecast,
            'currency' => $this->currency,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
