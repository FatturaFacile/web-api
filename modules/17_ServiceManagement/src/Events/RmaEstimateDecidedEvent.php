<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * Emitted when a repair estimate is approved, auto-approved or declined.
 */
final readonly class RmaEstimateDecidedEvent implements DomainEventInterface
{
    public function __construct(
        public string $rmaOrderId,
        public string $rmaNumber,
        public string $approvalStatus,
        public string $decidedVia,
        public float $estimateTotal,
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
        return 'esm_rma_order';
    }

    public function getAggregateId(): string
    {
        return $this->rmaOrderId;
    }

    public function getEventName(): string
    {
        return 'esm.rma.estimate_decided';
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
