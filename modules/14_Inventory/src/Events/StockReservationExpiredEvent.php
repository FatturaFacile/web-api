<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * A reservation lapsed at its expiry and its stock went back to the warehouse. It names the document it was made for
 * (`reserved_for_type` / `reserved_for_id`), so the module owning that document (Sales for `sales_order_item`) can
 * bring its own mirror of the reservation up to date without the inventory module knowing about it.
 */
final readonly class StockReservationExpiredEvent implements DomainEventInterface
{
    public function __construct(
        public string $reservationId,
        public string $stockItemId,
        public string $warehouseId,
        public string $reservedForType,
        public string $reservedForId,
        public string $quantity,
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
        return 'inventory.stock_reservation_expired';
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

    /**
     * @return array{reservation_id: string, stock_item_id: string, warehouse_id: string, reserved_for_type: string, reserved_for_id: string, quantity: string, tenant_id: string, company_id: string}
     */
    public function toPayload(): array
    {
        return [
            'reservation_id' => $this->reservationId,
            'stock_item_id' => $this->stockItemId,
            'warehouse_id' => $this->warehouseId,
            'reserved_for_type' => $this->reservedForType,
            'reserved_for_id' => $this->reservedForId,
            'quantity' => $this->quantity,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
