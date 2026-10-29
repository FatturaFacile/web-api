<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

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

    public function getAggregateType(): string
    {
        return 'mfg_production_order';
    }

    public function getAggregateId(): string
    {
        return $this->productionOrderId;
    }

    public function getEventName(): string
    {
        return 'mfg.finished_goods.produced';
    }

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
