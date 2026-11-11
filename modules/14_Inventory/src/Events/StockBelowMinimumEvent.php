<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * The available stock of an item in a warehouse has just dropped below the minimum stock level of its rule. Distinct
 * from ReorderPointReachedEvent (which fires at or below the reorder point whenever it is evaluated): this one fires
 * on the crossing only, see InventoryReplenishmentService::evaluateMinimumStock().
 */
final readonly class StockBelowMinimumEvent implements DomainEventInterface
{
    public function __construct(
        public string $reorderRuleId,
        public string $stockItemId,
        public string $warehouseId,
        public float $availableStock,
        public float $minimumStock,
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
        return 'stock_reorder_rule';
    }

    public function getAggregateId(): string
    {
        return $this->reorderRuleId;
    }

    public function getEventName(): string
    {
        return 'inventory.stock_below_minimum';
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function toPayload(): array
    {
        return get_object_vars($this);
    }
}
