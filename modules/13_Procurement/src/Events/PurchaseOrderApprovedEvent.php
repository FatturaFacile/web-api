<?php

declare(strict_types=1);

namespace Modules\Procurement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class PurchaseOrderApprovedEvent implements DomainEventInterface
{
    public float $totalAmount;

    public ?string $approvedByUserId;

    public string $tenantId;

    public string $companyId;

    public string $currency;

    public string $supplierId;

    public ?string $projectId;

    public function __construct(
        public string $purchaseOrderId,
        public string $poNumber,
        string|float|null $approvedByUserId = null,
        float|string $totalAmount = 0.0,
        string $currency = 'EUR',
        string $tenantId = '',
        string $companyId = '',
        string $supplierId = '',
        ?string $projectId = null,
        public string $eventId = '',
        public string $occurredAt = ''
    ) {
        if (is_numeric($approvedByUserId) && is_string($totalAmount)) {
            $this->totalAmount = (float) $approvedByUserId;
            $this->tenantId = (string) $totalAmount;
            $this->companyId = $currency;
            $this->approvedByUserId = $tenantId !== '' ? $tenantId : null;
            $this->currency = $companyId !== '' ? $companyId : 'EUR';
            $this->supplierId = $supplierId;
            $this->projectId = $projectId;
        } else {
            $this->approvedByUserId = $approvedByUserId !== null ? (string) $approvedByUserId : null;
            $this->totalAmount = (float) $totalAmount;
            $this->currency = $currency;
            $this->tenantId = $tenantId;
            $this->companyId = $companyId;
            $this->supplierId = $supplierId;
            $this->projectId = $projectId;
        }
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
        return 'procurement.purchase_order.approved';
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

    /**
     * @return array<string, mixed>
     */
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
