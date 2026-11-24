<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ProjectExpenseApprovedEvent implements DomainEventInterface
{
    public function __construct(
        public string $costEntryId,
        public string $projectId,
        public string $expenseCategory,
        public float $totalCost,
        public bool $isBillable,
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
        return 'project';
    }

    public function getAggregateId(): string
    {
        return $this->projectId;
    }

    public function getEventName(): string
    {
        return 'project.expense_approved';
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
            'expense_category' => $this->expenseCategory,
            'total_cost' => $this->totalCost,
            'is_billable' => $this->isBillable,
            'incurred_date' => $this->incurredDate,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
