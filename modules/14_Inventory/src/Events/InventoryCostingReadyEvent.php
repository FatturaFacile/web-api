<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class InventoryCostingReadyEvent implements DomainEventInterface
{
    /**
     * @param  array<string, mixed>  $costLayers
     */
    public function __construct(
        public string $stockItemId,
        public string $movementId,
        public float $unitCost,
        public float $totalCost,
        public string $costingMethod,
        public array $costLayers,
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
        return 'inventory_costing';
    }

    public function getAggregateId(): string
    {
        return $this->stockItemId;
    }

    public function getEventName(): string
    {
        return 'inventory.costing_ready';
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
            'stock_item_id' => $this->stockItemId,
            'movement_id' => $this->movementId,
            'unit_cost' => $this->unitCost,
            'total_cost' => $this->totalCost,
            'costing_method' => $this->costingMethod,
            'cost_layers' => $this->costLayers,
            'company_id' => $this->companyId,
            'tenant_id' => $this->tenantId,
        ];
    }
}
