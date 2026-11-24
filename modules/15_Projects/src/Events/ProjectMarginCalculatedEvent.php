<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ProjectMarginCalculatedEvent implements DomainEventInterface
{
    public function __construct(
        public string $projectId,
        public float $contractRevenue,
        public float $totalActualCost,
        public float $grossMarginAmount,
        public float $grossMarginPercentage,
        public float $cpi,
        public float $spi,
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
        return 'project.margin_calculated';
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
            'contract_revenue' => $this->contractRevenue,
            'total_actual_cost' => $this->totalActualCost,
            'gross_margin_amount' => $this->grossMarginAmount,
            'gross_margin_percentage' => $this->grossMarginPercentage,
            'cpi' => $this->cpi,
            'spi' => $this->spi,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
