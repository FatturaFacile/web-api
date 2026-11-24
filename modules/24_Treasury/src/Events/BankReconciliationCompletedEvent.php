<?php

declare(strict_types=1);

namespace Modules\Treasury\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class BankReconciliationCompletedEvent implements DomainEventInterface
{
    public function __construct(
        public string $reconciliationId,
        public string $bankAccountId,
        public string $periodEndDate,
        public float $statementBalance,
        public float $glBookBalance,
        public float $differenceAmount,
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
        return 'bank_reconciliation';
    }

    public function getAggregateId(): string
    {
        return $this->reconciliationId;
    }

    public function getEventName(): string
    {
        return 'treasury.bank_reconciliation.completed';
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
