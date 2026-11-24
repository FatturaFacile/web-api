<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class JournalPostedEvent implements DomainEventInterface
{
    public function __construct(
        public string $journalEntryId,
        public string $entryNumber,
        public string $fiscalPeriodId,
        public float $totalAmount,
        public string $ledgerType,
        public string $journalType,
        public string $postingDate,
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
        return 'journal_entry';
    }

    public function getAggregateId(): string
    {
        return $this->journalEntryId;
    }

    public function getEventName(): string
    {
        return 'general_ledger.journal.posted';
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
