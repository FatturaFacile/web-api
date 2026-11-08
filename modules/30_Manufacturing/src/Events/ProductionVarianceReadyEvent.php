<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * The variance of a closed production order was settled in the ledger.
 */
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
        return 'mfg.accounting.variance_ready';
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
            'standard_cost' => $this->standardCost,
            'actual_cost' => $this->actualCost,
            'variance' => $this->variance,
            'variance_type' => $this->varianceType,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
