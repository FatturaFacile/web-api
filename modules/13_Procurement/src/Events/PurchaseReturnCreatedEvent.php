<?php

declare(strict_types=1);

namespace Modules\Procurement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class PurchaseReturnCreatedEvent implements DomainEventInterface
{
    public function __construct(
        public string $returnId,
        public string $returnNumber,
        public string $purchaseOrderId,
        public string $supplierId,
        public float $totalAmount,
        public string $tenantId,
        public string $companyId,
        public string $eventId = '',
        public string $occurredAt = ''
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getEventName(): string
    {
        return 'procurement.purchase_return.created';
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): ?string
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
            'return_number' => $this->returnNumber,
            'purchase_order_id' => $this->purchaseOrderId,
            'supplier_id' => $this->supplierId,
            'total_amount' => $this->totalAmount,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
