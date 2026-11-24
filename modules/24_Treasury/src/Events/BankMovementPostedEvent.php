<?php

declare(strict_types=1);

namespace Modules\Treasury\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * A treasury bank movement (internal transfer, bank fee or interest) was recorded and posted to the ledger.
 */
final readonly class BankMovementPostedEvent implements DomainEventInterface
{
    public function __construct(
        public string $movementId,
        public string $movementType,
        public string $bankAccountId,
        public ?string $counterBankAccountId,
        public string $amount,
        public string $currency,
        public string $movementDate,
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
        return 'treasury_bank_movement';
    }

    public function getAggregateId(): string
    {
        return $this->movementId;
    }

    public function getEventName(): string
    {
        return 'treasury.bank_movement.posted';
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
