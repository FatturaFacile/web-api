<?php

declare(strict_types=1);

namespace Modules\Treasury\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class CollectionReceivedEvent implements DomainEventInterface
{
    public function __construct(
        public string $collectionId,
        public string $customerId,
        public ?string $invoiceId,
        public float $collectedAmount,
        public string $collectionDate,
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
        return 'receivable_collection';
    }

    public function getAggregateId(): string
    {
        return $this->collectionId;
    }

    public function getEventName(): string
    {
        return 'treasury.collection.received';
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
