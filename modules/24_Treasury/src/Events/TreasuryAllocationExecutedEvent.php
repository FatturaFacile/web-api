<?php

declare(strict_types=1);

namespace Modules\Treasury\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class TreasuryAllocationExecutedEvent implements DomainEventInterface
{
    public function __construct(
        public string $allocationId,
        public string $sourceBankAccountId,
        public string $destinationBankAccountId,
        public float $amount,
        public string $currency,
        public ?string $journalEntryId,
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
        return 'intercompany_funding';
    }

    public function getAggregateId(): string
    {
        return $this->allocationId;
    }

    public function getEventName(): string
    {
        return 'treasury.allocation.executed';
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
