<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * Emitted once per loaner still out after the repaired unit was returned and the grace period elapsed.
 */
final readonly class LoanerOverdueEvent implements DomainEventInterface
{
    public function __construct(
        public string $loanerId,
        public string $rmaOrderId,
        public string $loanerSerialNumber,
        public string $customerId,
        public int $daysOutstanding,
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
        return 'esm_loaner';
    }

    public function getAggregateId(): string
    {
        return $this->loanerId;
    }

    public function getEventName(): string
    {
        return 'esm.loaner.overdue';
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
