<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class InventoryCountCompletedEvent implements DomainEventInterface
{
    public function __construct(
        public string $sessionNumber,
        public string $warehouseId,
        public float $totalValuationChange,
        public string $status,
        public string $tenantId = '',
        public string $companyId = '',
        public string $sessionId = '',
        public string $eventId = '',
        public string $occurredAt = ''
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'inventory_count_session';
    }

    public function getAggregateId(): string
    {
        if ($this->sessionId !== '' && Str::isUuid($this->sessionId)) {
            return $this->sessionId;
        }

        if (Str::isUuid($this->sessionNumber)) {
            return $this->sessionNumber;
        }

        return (string) Str::uuid7();
    }

    public function getEventName(): string
    {
        return 'inventory.count_completed';
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
            'session_number' => $this->sessionNumber,
            'warehouse_id' => $this->warehouseId,
            'total_valuation_change' => $this->totalValuationChange,
            'status' => $this->status,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
