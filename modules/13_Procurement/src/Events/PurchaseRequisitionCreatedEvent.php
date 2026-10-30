<?php

declare(strict_types=1);

namespace Modules\Procurement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class PurchaseRequisitionCreatedEvent implements DomainEventInterface
{
    public float $totalEstimatedAmount;

    public string $requestorUserId;

    public string $tenantId;

    public string $companyId;

    public string $currency;

    public function __construct(
        public string $requisitionId,
        public string $requisitionNumber,
        float|string $totalEstimatedAmount = 0.0,
        string|float $requestorUserId = '',
        string $tenantId = '',
        string $companyId = '',
        string $currency = 'EUR',
        public string $eventId = '',
        public string $occurredAt = ''
    ) {
        if (is_numeric($requestorUserId) && ! is_numeric($totalEstimatedAmount)) {
            $this->requestorUserId = (string) $totalEstimatedAmount;
            $this->totalEstimatedAmount = (float) $requestorUserId;
            $this->currency = $tenantId !== '' ? $tenantId : 'EUR';
            $this->tenantId = $companyId;
            $this->companyId = $currency;
        } else {
            $this->totalEstimatedAmount = (float) $totalEstimatedAmount;
            $this->requestorUserId = (string) $requestorUserId;
            $this->tenantId = $tenantId;
            $this->companyId = $companyId;
            $this->currency = $currency;
        }
    }

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'purchase_requisition';
    }

    public function getAggregateId(): string
    {
        return $this->requisitionId;
    }

    public function getEventName(): string
    {
        return 'procurement.requisition.created';
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

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'requisition_id' => $this->requisitionId,
            'requisition_number' => $this->requisitionNumber,
            'total_estimated_amount' => $this->totalEstimatedAmount,
            'requestor_user_id' => $this->requestorUserId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
