<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ProjectBudgetExceededEvent implements DomainEventInterface
{
    public function __construct(
        public string $projectId,
        public string $projectNumber,
        public float $budgetCost,
        public float $actualCost,
        public float $varianceAmount,
        public float $consumptionPercentage,
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
        return 'project.budget_exceeded';
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
            'project_number' => $this->projectNumber,
            'budget_cost' => $this->budgetCost,
            'actual_cost' => $this->actualCost,
            'variance_amount' => $this->varianceAmount,
            'consumption_percentage' => $this->consumptionPercentage,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
