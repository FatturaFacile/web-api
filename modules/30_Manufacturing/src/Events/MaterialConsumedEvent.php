<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

final class MaterialConsumedEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $productionOrderId,
        public string $componentItemId,
        public float $quantityConsumed,
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
        return 'mfg.material.consumed';
    }

    public function toPayload(): array
    {
        return [
            'production_order_id' => $this->productionOrderId,
            'component_item_id' => $this->componentItemId,
            'quantity_consumed' => $this->quantityConsumed,
            'unit_cost' => $this->unitCost,
            'total_cost' => $this->totalCost,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
