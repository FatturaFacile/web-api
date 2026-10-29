<?php

declare(strict_types=1);

namespace Modules\Procurement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class InventoryReceiptRequestedEvent implements DomainEventInterface
{
    /**
     * @param  array<int, array{purchase_order_line_id: string, product_id?: ?string, quantity: float, lot_number?: ?string}>  $items
     */
    public function __construct(
        public string $receiptId,
        public string $purchaseOrderId,
        public string $warehouseId,
        public array $items,
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
        return 'purchase_receipt';
    }

    public function getAggregateId(): string
    {
        return $this->receiptId;
    }

    public function getEventName(): string
    {
        return 'inventory.receipt.requested';
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
            'receipt_id' => $this->receiptId,
            'purchase_order_id' => $this->purchaseOrderId,
            'warehouse_id' => $this->warehouseId,
            'items' => $this->items,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
