<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ProjectCashFlowForecastEvent implements DomainEventInterface
{
    public function __construct(
        public string $projectId,
        public string $forecastPeriod,
        public float $projectedCashInflow,
        public float $projectedCashOutflow,
        public float $netProjectCashFlow,
        public string $tenantId,
        public string $companyId,
        public string $eventId = '',
        public string $occurredAt = ''
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'project';
    }

    public function getAggregateId(): string
    {
        return $this->projectId;
    }

    public function getEventName(): string
    {
        return 'project.cash_flow_forecast';
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
        return $this->occurredAt !== '' ? $this->occurredAt : now()->toIso8601String();
    }

    public function toPayload(): array
    {
        return [
            'project_id' => $this->projectId,
            'forecast_period' => $this->forecastPeriod,
            'projected_cash_inflow' => $this->projectedCashInflow,
            'projected_cash_outflow' => $this->projectedCashOutflow,
            'net_project_cash_flow' => $this->netProjectCashFlow,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
