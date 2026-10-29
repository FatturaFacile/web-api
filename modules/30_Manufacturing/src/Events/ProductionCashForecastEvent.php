<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

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

    public function getAggregateType(): string
    {
        return 'mfg_treasury_forecast';
    }

    public function getAggregateId(): string
    {
        return $this->tenantId;
    }

    public function getEventName(): string
    {
        return 'mfg.treasury.production_cash_forecast';
    }

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
