<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * Stock has just dropped below a configured minimum. Distinct from ReorderPointReachedEvent (which fires at or below the
 * reorder point whenever it is evaluated): this one fires on the crossing only, see
 * InventoryReplenishmentService::evaluateMinimumStock() and evaluateProductMinimumStock().
 *
 * Two scopes, each with its own minimum and its own crossing, so both can fire for one movement:
 * - "warehouse": the available stock of an item in one warehouse fell below min_stock_level of its reorder rule
 *   (reorderRuleId, stockItemId, warehouseId set);
 * - "product": the stock of a catalog product, across all warehouses, fell below products.min_stock (productId set;
 *   reorderRuleId, stockItemId and warehouseId are null).
 */
final readonly class StockBelowMinimumEvent implements DomainEventInterface
{
    public const string ScopeWarehouse = 'warehouse';

    public const string ScopeProduct = 'product';

    public function __construct(
        public string $scope,
        public ?string $reorderRuleId,
        public ?string $stockItemId,
        public ?string $warehouseId,
        public ?string $productId,
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
        return $this->scope === self::ScopeProduct ? 'product' : 'stock_reorder_rule';
    }

    public function getAggregateId(): string
    {
        return (string) ($this->scope === self::ScopeProduct ? $this->productId : $this->reorderRuleId);
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
