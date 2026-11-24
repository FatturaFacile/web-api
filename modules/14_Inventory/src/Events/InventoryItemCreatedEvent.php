<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class InventoryItemCreatedEvent implements DomainEventInterface
{
    public function __construct(
        public string $itemId,
        public string $sku,
        public string $name,
        public string $companyId,
        public ?string $userId = null,
        public string $tenantId = '',
        public string $eventId = '',
        public string $occurredAt = ''
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'stock_item';
    }

    public function getAggregateId(): string
    {
        return $this->itemId;
    }

    public function getEventName(): string
    {
        return 'inventory.item_created';
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
        return [
            'item_id' => $this->itemId,
            'sku' => $this->sku,
            'name' => $this->name,
            'company_id' => $this->companyId,
            'user_id' => $this->userId,
        ];
    }
}
