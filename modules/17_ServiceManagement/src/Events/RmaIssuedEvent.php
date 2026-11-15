<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * Emitted when a depot repair RMA is issued after the serial warranty lookup.
 */
final readonly class RmaIssuedEvent implements DomainEventInterface
{
    public function __construct(
        public string $rmaOrderId,
        public string $rmaNumber,
        public string $equipmentPassportId,
        public string $serialNumber,
        public string $customerId,
        public string $warrantyStatus,
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
        return 'esm_rma_order';
    }

    public function getAggregateId(): string
    {
        return $this->rmaOrderId;
    }

    public function getEventName(): string
    {
        return 'esm.rma.issued';
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
