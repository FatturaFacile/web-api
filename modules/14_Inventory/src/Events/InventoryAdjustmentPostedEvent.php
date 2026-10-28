<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class InventoryAdjustmentPostedEvent implements DomainEventInterface
{
    /**
     * @param  array<string, mixed>  $lines
     */
    public function __construct(
        public string $adjustmentId,
        public string $warehouseId,
        public string $adjustmentType,
        public string $reasonCode,
        public float $totalCostImpact,
        public array $lines,
        public string $companyId,
        public string $tenantId = '',
        public string $eventId = '',
        public string $occurredAt = ''
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'stock_adjustment';
    }

    public function getAggregateId(): string
    {
        return $this->adjustmentId;
    }

    public function getEventName(): string
    {
        return 'inventory.adjustment_posted';
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
            'adjustment_id' => $this->adjustmentId,
            'warehouse_id' => $this->warehouseId,
            'adjustment_type' => $this->adjustmentType,
            'reason_code' => $this->reasonCode,
            'total_cost_impact' => $this->totalCostImpact,
            'lines' => $this->lines,
            'company_id' => $this->companyId,
            'tenant_id' => $this->tenantId,
        ];
    }
}
