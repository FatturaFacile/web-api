<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ProjectCostReadyEvent implements DomainEventInterface
{
    public function __construct(
        public string $costEntryId,
        public string $projectId,
        public string $costCategory,
        public float $totalCost,
        public string $debitAccountCode,
        public string $creditAccountCode,
        public string $incurredDate,
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
        return 'project_cost_entry';
    }

    public function getAggregateId(): string
    {
        return $this->costEntryId;
    }

    public function getEventName(): string
    {
        return 'project.cost_ready';
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
            'cost_entry_id' => $this->costEntryId,
            'project_id' => $this->projectId,
            'cost_category' => $this->costCategory,
            'total_cost' => $this->totalCost,
            'debit_account_code' => $this->debitAccountCode,
            'credit_account_code' => $this->creditAccountCode,
            'incurred_date' => $this->incurredDate,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
