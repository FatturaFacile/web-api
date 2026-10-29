<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

final class ProductionOrderCreatedEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $productionOrderId,
        public string $orderNumber,
        public string $mfgItemId,
        public float $targetQuantity,
        public string $plannedStartDate,
        public string $plannedEndDate,
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
        return 'mfg.production_order.created';
    }

    public function toPayload(): array
    {
        return [
            'production_order_id' => $this->productionOrderId,
            'order_number' => $this->orderNumber,
            'mfg_item_id' => $this->mfgItemId,
            'target_quantity' => $this->targetQuantity,
            'planned_start_date' => $this->plannedStartDate,
            'planned_end_date' => $this->plannedEndDate,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
