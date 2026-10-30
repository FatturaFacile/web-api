<?php

declare(strict_types=1);

namespace Modules\Procurement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class PurchaseOrderCreatedEvent implements DomainEventInterface
{
    public function __construct(
        public string $purchaseOrderId,
        public string $poNumber,
        public string $supplierId,
        public float $totalAmount,
        public string $tenantId,
        public string $companyId,
        public string $currency = 'EUR',
        public string $eventId = '',
        public string $occurredAt = ''
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'purchase_order';
    }

    public function getAggregateId(): string
    {
        return $this->purchaseOrderId;
    }

    public function getEventName(): string
    {
        return 'procurement.purchase_order.created';
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return (string) ($this->companyId ?? '');
    }

    public function getOccurredAt(): string
    {
        return $this->occurredAt !== '' ? $this->occurredAt : now()->toIso8601String();
    }

    public function toPayload(): array
    {
        return [
            'purchase_order_id' => $this->purchaseOrderId,
            'po_number' => $this->poNumber,
            'supplier_id' => $this->supplierId,
            'total_amount' => $this->totalAmount,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
