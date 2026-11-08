<?php

declare(strict_types=1);

namespace Modules\Treasury\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * A cash register was closed for a day against the counted cash; a difference was posted to the ledger.
 */
final readonly class CashRegisterClosedEvent implements DomainEventInterface
{
    public function __construct(
        public string $cashRegisterId,
        public string $closingId,
        public string $closingDate,
        public string $expectedBalance,
        public string $countedBalance,
        public string $difference,
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
        return 'treasury_cash_register';
    }

    public function getAggregateId(): string
    {
        return $this->cashRegisterId;
    }

    public function getEventName(): string
    {
        return 'treasury.cash_register.closed';
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return get_object_vars($this);
    }
}
