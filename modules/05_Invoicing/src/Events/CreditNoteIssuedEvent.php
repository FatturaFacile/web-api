<?php

declare(strict_types=1);

namespace Modules\Invoicing\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class CreditNoteIssuedEvent implements DomainEventInterface
{
    /**
     * @param array<int, array<string, mixed>> $adjustmentLines
     */
    public function __construct(
        public string $creditNoteId,
        public string $creditNoteNumber,
        public ?string $originalInvoiceId,
        public string $customerId,
        public float $creditedAmount,
        public array $adjustmentLines,
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
        return 'credit_note';
    }

    public function getAggregateId(): string
    {
        return $this->creditNoteId;
    }

    public function getEventName(): string
    {
        return 'invoicing.credit_note.issued';
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
