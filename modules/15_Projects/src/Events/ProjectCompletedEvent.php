<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ProjectCompletedEvent implements DomainEventInterface
{
    public function __construct(
        public string $projectId,
        public string $projectNumber,
        public float $finalActualCost,
        public float $finalContractValue,
        public float $finalMarginAmount,
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
        return 'project.completed';
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
            'final_actual_cost' => $this->finalActualCost,
            'final_contract_value' => $this->finalContractValue,
            'final_margin_amount' => $this->finalMarginAmount,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
