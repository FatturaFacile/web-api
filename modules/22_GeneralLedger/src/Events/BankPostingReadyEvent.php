<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class BankPostingReadyEvent implements DomainEventInterface
{
    public function __construct(
        public string $journalLineId,
        public string $bankAccountId,
        public float $amount,
        public string $direction, // 'debit', 'credit'
        public string $valueDate,
        public string $journalEntryId,
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
        return 'bank_posting';
    }

    public function getAggregateId(): string
    {
        return $this->journalLineId;
    }

    public function getEventName(): string
    {
        return 'treasury.bank_posting.ready';
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
