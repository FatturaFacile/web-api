<?php

declare(strict_types=1);

namespace Modules\Procurement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class PurchaseRequisitionCreatedEvent implements DomainEventInterface
{
    public function __construct(
        public string $requisitionId,
        public string $requisitionNumber,
        public float $totalEstimatedAmount,
        public string $requestorUserId,
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
        return 'procurement.requisition.created';
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
            'requisition_id' => $this->requisitionId,
            'requisition_number' => $this->requisitionNumber,
            'total_estimated_amount' => $this->totalEstimatedAmount,
            'requestor_user_id' => $this->requestorUserId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
