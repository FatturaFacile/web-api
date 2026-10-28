<?php

declare(strict_types=1);

namespace Modules\Procurement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class PurchaseOrderCancelledEvent implements DomainEventInterface
{
    public function __construct(
        public string $purchaseOrderId,
        public string $poNumber,
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

    public function getEventName(): string
    {
        return 'procurement.purchase_order.cancelled';
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
            'purchase_order_id' => $this->purchaseOrderId,
            'po_number' => $this->poNumber,
            'reason' => $this->reason,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
