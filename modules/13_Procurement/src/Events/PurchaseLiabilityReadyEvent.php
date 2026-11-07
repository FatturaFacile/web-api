<?php

declare(strict_types=1);

namespace Modules\Procurement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class PurchaseLiabilityReadyEvent implements DomainEventInterface
{
    public function __construct(
        public string $matchLogId,
        public string $purchaseOrderId,
        public string $supplierId,
        public float $netAmount,
        public float $taxAmount,
        public float $grossAmount,
        public string $currency,
        public string $vendorInvoiceNumber,
        public string $tenantId,
        public string $companyId,
        public string $eventId = '',
        public string $occurredAt = '',
        public ?string $documentDate = null,
        public ?string $dueDate = null,
        public bool $isCreditNote = false,
        public float $exchangeRate = 1.0,
        public float $withholdingTaxAmount = 0.0,
        public float $contributionAmount = 0.0,
        /** @var list<array{account_id: string, amount: float}> Share of the net amount that goes to specific cost accounts */
        public array $costAllocations = [],
        /** Stamp duty the supplier charged in the document total, a cost outside the VAT base. */
        public float $stampDutyAmount = 0.0,
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'three_way_match_log';
    }

    public function getAggregateId(): string
    {
        return $this->matchLogId;
    }

    public function getEventName(): string
    {
        return 'accounting.liability.ready';
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
            'match_log_id' => $this->matchLogId,
            'purchase_order_id' => $this->purchaseOrderId,
            'supplier_id' => $this->supplierId,
            'net_amount' => $this->netAmount,
            'tax_amount' => $this->taxAmount,
            'gross_amount' => $this->grossAmount,
            'currency' => $this->currency,
            'vendor_invoice_number' => $this->vendorInvoiceNumber,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
