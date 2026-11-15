<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * Emitted when repair costs of a covered/goodwill RMA are charged to the warranty provision cost bucket (GL / Inventory consumers).
 */
final readonly class RmaWarrantyCostChargedEvent implements DomainEventInterface
{
    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function __construct(
        public string $rmaOrderId,
        public string $rmaNumber,
        public string $costBucketCode,
        public float $partsCost,
        public float $labourCost,
        public float $totalCost,
        public string $currency,
        public array $lines,
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
        return 'esm.rma.warranty_cost_charged';
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
