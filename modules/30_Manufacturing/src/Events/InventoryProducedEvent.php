<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * Stock of the output item was received from a production order.
 */
final class InventoryProducedEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $productionOrderId,
        public string $stockItemId,
        public float $quantity,
        public float $unitCost,
        string $tenantId,
        ?string $companyId = null,
        ?string $eventId = null,
        ?string $occurredAt = null
    ) {
        parent::__construct($tenantId, $companyId, $eventId, $occurredAt);
    }

    /**
     * Aggregate type of the event.
     */
    public function getAggregateType(): string
    {
        return 'mfg_production_order';
    }

    /**
     * Aggregate id of the event.
     */
    public function getAggregateId(): string
    {
        return $this->productionOrderId;
    }

    /**
     * Name of the event in the outbox.
     */
    public function getEventName(): string
    {
        return 'mfg.inventory.produced';
    }

    /**
     * Payload of the event.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'production_order_id' => $this->productionOrderId,
            'stock_item_id' => $this->stockItemId,
            'quantity' => $this->quantity,
            'unit_cost' => $this->unitCost,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
