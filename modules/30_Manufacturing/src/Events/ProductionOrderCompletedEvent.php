<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

final class ProductionOrderCompletedEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $productionOrderId,
        public string $orderNumber,
        public string $mfgItemId,
        public float $completedQuantity,
        public float $scrappedQuantity,
        public float $actualCostTotal,
        public float $varianceTotal,
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
        return 'mfg.production_order.completed';
    }

    public function toPayload(): array
    {
        return [
            'production_order_id' => $this->productionOrderId,
            'order_number' => $this->orderNumber,
            'mfg_item_id' => $this->mfgItemId,
            'completed_quantity' => $this->completedQuantity,
            'scrapped_quantity' => $this->scrappedQuantity,
            'actual_cost_total' => $this->actualCostTotal,
            'variance_total' => $this->varianceTotal,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
