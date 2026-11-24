<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ServicePipelineForecastEvent implements DomainEventInterface
{
    public function __construct(
        public string $forecastPeriod,
        public int $pendingTicketsCount,
        public int $pendingWorkOrdersCount,
        public float $estimatedPipelineValue,
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
        return 'esm_service_contract';
    }

    public function getAggregateId(): string
    {
        return $this->tenantId;
    }

    public function getEventName(): string
    {
        return 'treasury.esm.pipeline_forecast';
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
        return get_object_vars($this);
    }
}
