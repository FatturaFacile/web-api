<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

final class ProductionVarianceReadyEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $productionOrderId,
        public float $standardCost,
        public float $actualCost,
        public float $variance,
        public string $varianceType,
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
        return 'mfg.accounting.variance_ready';
    }

    public function toPayload(): array
    {
        return [
            'production_order_id' => $this->productionOrderId,
            'standard_cost' => $this->standardCost,
            'actual_cost' => $this->actualCost,
            'variance' => $this->variance,
            'variance_type' => $this->varianceType,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
