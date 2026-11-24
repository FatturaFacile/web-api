<?php

declare(strict_types=1);

namespace Modules\Procurement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class SupplierReturnRequestedEvent implements DomainEventInterface
{
    /**
     * @param  array<int, array{product_id: string, quantity: float, warehouse_id?: ?string, lot_number?: ?string}>  $lines
     */
    public function __construct(
        public string $returnId,
        public string $purchaseOrderId,
        public string $supplierId,
        public array $lines,
        public string $reason,
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
        return 'purchase_return';
    }

    public function getAggregateId(): string
    {
        return $this->returnId;
    }

    public function getEventName(): string
    {
        return 'inventory.supplier_return.requested';
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
            'return_id' => $this->returnId,
            'purchase_order_id' => $this->purchaseOrderId,
            'supplier_id' => $this->supplierId,
            'lines' => $this->lines,
            'reason' => $this->reason,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
