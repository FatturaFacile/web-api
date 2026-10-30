<?php

declare(strict_types=1);

namespace Modules\Procurement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ThreeWayMatchPassedEvent implements DomainEventInterface
{
    public string $receiptId;

    public string $passiveInvoiceId;

    public float $invoiceTotal;

    public string $tenantId;

    public string $companyId;

    public function __construct(
        public string $matchLogId,
        public string $purchaseOrderId,
        string $receiptId,
        float|string $passiveInvoiceId,
        float|string $invoiceTotal = 0.0,
        string $tenantId = '',
        string $companyId = '',
        public string $eventId = '',
        public string $occurredAt = ''
    ) {
        if (is_numeric($passiveInvoiceId)) {
            $this->receiptId = $receiptId;
            $this->passiveInvoiceId = '';
            $this->invoiceTotal = (float) $passiveInvoiceId;
            $this->tenantId = (string) $invoiceTotal;
            $this->companyId = $tenantId;
        } else {
            $this->receiptId = $receiptId;
            $this->passiveInvoiceId = (string) $passiveInvoiceId;
            $this->invoiceTotal = (float) $invoiceTotal;
            $this->tenantId = $tenantId;
            $this->companyId = $companyId;
        }
    }

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'three_way_match';
    }

    public function getAggregateId(): string
    {
        return $this->matchLogId;
    }

    public function getEventName(): string
    {
        return 'procurement.three_way_match.passed';
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
