<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * Production of an order was completed with the cost of its finished goods.
 */
final class ProductionCompletedEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $productionOrderId,
        public string $mfgItemId,
        public float $quantity,
        public float $finishedGoodsCost,
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
        return 'mfg.accounting.production_completed';
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
            'mfg_item_id' => $this->mfgItemId,
            'quantity' => $this->quantity,
            'finished_goods_cost' => $this->finishedGoodsCost,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
