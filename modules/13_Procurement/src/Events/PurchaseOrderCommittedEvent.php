<?php

declare(strict_types=1);

namespace Modules\Procurement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class PurchaseOrderCommittedEvent implements DomainEventInterface
{
    public string $purchaseOrderNumber;

    public function __construct(
        public string $purchaseOrderId,
        public string $poNumber,
        public string $supplierId,
        public float $committedAmount,
        public string $currency,
        public string $tenantId,
        public string $companyId,
        public string $eventId = '',
        public string $occurredAt = '',
        ?string $purchaseOrderNumber = null
    ) {
        $this->purchaseOrderNumber = $purchaseOrderNumber ?? $poNumber;
    }

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
        return 'accounting.purchase_order.committed';
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
            'purchase_order_id' => $this->purchaseOrderId,
            'po_number' => $this->poNumber,
            'purchase_order_number' => $this->purchaseOrderNumber,
            'supplier_id' => $this->supplierId,
            'committed_amount' => $this->committedAmount,
            'currency' => $this->currency,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
