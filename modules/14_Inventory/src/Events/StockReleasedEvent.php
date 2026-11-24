<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class StockReleasedEvent implements DomainEventInterface
{
    public function __construct(
        public string $reservationId,
        public string $stockItemId,
        public string $warehouseId,
        public float $quantity,
        public string $tenantId = '',
        public string $companyId = '',
        public string $eventId = '',
        public string $occurredAt = ''
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'stock_reservation';
    }

    public function getAggregateId(): string
    {
        return $this->reservationId;
    }

    public function getEventName(): string
    {
        return 'inventory.stock_released';
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
            'reservation_id' => $this->reservationId,
            'stock_item_id' => $this->stockItemId,
            'warehouse_id' => $this->warehouseId,
            'quantity' => $this->quantity,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
