<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * An approved out-of-pocket project expense: the General Ledger books the cost by nature against the reimbursement
 * payable, since no other document records it.
 */
final readonly class ProjectExpensePostingReadyEvent implements DomainEventInterface
{
    /**
     * @param  string  $amount  decimal string with two decimals
     */
    public function __construct(
        public string $costEntryId,
        public string $projectId,
        public string $expenseCategory,
        public string $amount,
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
        return 'project';
    }

    public function getAggregateId(): string
    {
        return $this->projectId;
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
