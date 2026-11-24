<?php

declare(strict_types=1);

namespace Modules\Invoicing\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class DeliveryNoteIssuedEvent implements DomainEventInterface
{
    public function __construct(
        public string $deliveryNoteId,
        public string $ddtNumber,
        public string $customerId,
        public ?string $salesOrderId,
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
        return 'delivery_note';
    }

    public function getAggregateId(): string
    {
        return $this->deliveryNoteId;
    }

    public function getEventName(): string
    {
        return 'sales.delivery_note.issued';
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
