<?php

declare(strict_types=1);

namespace Modules\Procurement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ThreeWayMatchDiscrepancyDetectedEvent implements DomainEventInterface
{
    public string $receiptId;

    public string $passiveInvoiceId;

    public float $varianceAmount;

    public float $variancePercentage;

    /** @var array<int, mixed> */
    public array $discrepancyDetails;

    public string $tenantId;

    public string $companyId;

    /**
     * @param  array<int, mixed>  $discrepancyDetails
     */
    public function __construct(
        public string $matchLogId,
        public string $purchaseOrderId,
        string $receiptId,
        float|string $passiveInvoiceId,
        float|string|array $varianceAmount = 0.0,
        float|string|array $variancePercentage = 0.0,
        float|string|array $discrepancyDetails = [],
        string $tenantId = '',
        string $companyId = '',
        public string $eventId = '',
        public string $occurredAt = ''
    ) {
        if (is_numeric($passiveInvoiceId)) {
            $this->receiptId = $receiptId;
            $this->passiveInvoiceId = '';
            $this->varianceAmount = is_numeric($variancePercentage) ? (float) $variancePercentage : 0.0;
            $this->variancePercentage = is_numeric($discrepancyDetails) ? (float) $discrepancyDetails : 0.0;
            $this->discrepancyDetails = [];
            $this->tenantId = $tenantId;
            $this->companyId = $companyId;
        } else {
            $this->receiptId = $receiptId;
            $this->passiveInvoiceId = (string) $passiveInvoiceId;
            $this->varianceAmount = (float) $varianceAmount;
            $this->variancePercentage = (float) $variancePercentage;
            $this->discrepancyDetails = is_array($discrepancyDetails) ? $discrepancyDetails : [];
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
        return 'procurement.three_way_match.discrepancy_detected';
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
            'variance_amount' => $this->varianceAmount,
            'variance_percentage' => $this->variancePercentage,
            'discrepancy_details' => $this->discrepancyDetails,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
