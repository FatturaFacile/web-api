<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ProjectExpensePostingReadyEvent implements DomainEventInterface
{
    public function __construct(
        public string $costEntryId,
        public string $projectId,
        public string $expenseCategory,
        public float $amount,
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
        return 'project_expense';
    }

    public function getAggregateId(): string
    {
        return $this->costEntryId;
    }

    public function getEventName(): string
    {
        return 'project.expense.posting_ready';
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function toPayload(): array
    {
        return get_object_vars($this);
    }
}
