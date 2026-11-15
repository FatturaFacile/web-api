<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * Emitted when a vendor warranty claim-back is drafted for a defective part.
 */
final readonly class VendorClaimOpenedEvent implements DomainEventInterface
{
    public function __construct(
        public string $claimId,
        public string $rmaOrderId,
        public string $supplierId,
        public ?string $defectivePartSerial,
        public float $claimedAmount,
        public string $currency,
        public string $tenantId,
        public string $companyId,
        public string $eventId = '',
        public string $occurredAt = ''
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'esm_vendor_warranty_claim';
    }

    public function getAggregateId(): string
    {
        return $this->claimId;
    }

    public function getEventName(): string
    {
        return 'esm.vendor_claim.opened';
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
        return get_object_vars($this);
    }
}
