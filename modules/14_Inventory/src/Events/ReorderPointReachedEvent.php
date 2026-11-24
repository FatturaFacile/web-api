<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ReorderPointReachedEvent implements DomainEventInterface
{
    public function __construct(
        public string $reorderRuleId,
        public string $stockItemId,
        public string $warehouseId,
        public float $currentStock,
        public float $reorderPoint,
        public float $suggestedOrderQty,
        public ?string $preferredSupplierId,
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
        return 'stock_reorder_rule';
    }

    public function getAggregateId(): string
    {
        return $this->reorderRuleId;
    }

    public function getEventName(): string
    {
        return 'inventory.reorder_point_reached';
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
            'reorder_rule_id' => $this->reorderRuleId,
            'stock_item_id' => $this->stockItemId,
            'warehouse_id' => $this->warehouseId,
            'current_stock' => $this->currentStock,
            'reorder_point' => $this->reorderPoint,
            'suggested_order_qty' => $this->suggestedOrderQty,
            'preferred_supplier_id' => $this->preferredSupplierId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
