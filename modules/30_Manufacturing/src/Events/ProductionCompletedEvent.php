<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

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
        return 'mfg.accounting.production_completed';
    }

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
