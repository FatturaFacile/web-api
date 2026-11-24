<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * Output of a production order was received into stock at its standard unit cost.
 */
final class FinishedGoodsProducedEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $productionOrderId,
        public string $mfgItemId,
        public float $quantityProduced,
        public float $unitCost,
        public float $totalCost,
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
        return 'mfg.finished_goods.produced';
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
            'quantity_produced' => $this->quantityProduced,
            'unit_cost' => $this->unitCost,
            'total_cost' => $this->totalCost,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
