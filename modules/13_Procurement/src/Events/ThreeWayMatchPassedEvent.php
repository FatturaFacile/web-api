<?php

declare(strict_types=1);

namespace Modules\Procurement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ThreeWayMatchPassedEvent implements DomainEventInterface
{
    public function __construct(
        public string $matchLogId,
        public string $purchaseOrderId,
        public string $receiptId,
        public string $passiveInvoiceId,
        public float $invoiceTotal,
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
        return 'procurement.three_way_match.passed';
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
            'match_log_id' => $this->matchLogId,
            'purchase_order_id' => $this->purchaseOrderId,
            'receipt_id' => $this->receiptId,
            'passive_invoice_id' => $this->passiveInvoiceId,
            'invoice_total' => $this->invoiceTotal,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
