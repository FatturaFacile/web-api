<?php

declare(strict_types=1);

namespace Modules\Invoicing\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class GoodsDispatchedEvent implements DomainEventInterface
{
    /**
     * @param  array<int, array<string, mixed>>  $dispatchedItems
     */
    public function __construct(
        public string $deliveryNoteId,
        public ?string $salesOrderId,
        public string $warehouseId,
        public array $dispatchedItems,
        public string $dispatchedAt,
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
        return 'sales.goods.dispatched';
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
