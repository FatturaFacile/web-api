<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ReceivableReadyEvent implements DomainEventInterface
{
    public function __construct(
        public string $customerLedgerId,
        public string $customerId,
        public float $openAmount,
        public string $currency,
        public string $dueDate,
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
        return 'customer_ledger';
    }

    public function getAggregateId(): string
    {
        return $this->customerLedgerId;
    }

    public function getEventName(): string
    {
        return 'treasury.receivable.ready';
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
