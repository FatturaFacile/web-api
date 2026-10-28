<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class InventoryValuationCalculatedEvent implements DomainEventInterface
{
    public function __construct(
        public string $snapshotId,
        public string $stockItemId,
        public string $warehouseId,
        public string $valuationMethod,
        public float $totalValuation,
        public string $tenantId = '',
        public string $companyId = '',
        public string $eventId = '',
        public string $occurredAt = ''
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'stock_valuation_snapshot';
    }

    public function getAggregateId(): string
    {
        return $this->snapshotId;
    }

    public function getEventName(): string
    {
        return 'inventory.valuation_calculated';
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
            'snapshot_id' => $this->snapshotId,
            'stock_item_id' => $this->stockItemId,
            'warehouse_id' => $this->warehouseId,
            'valuation_method' => $this->valuationMethod,
            'total_valuation' => $this->totalValuation,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
