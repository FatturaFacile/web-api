<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

final class WIPRecognizedEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $productionOrderId,
        public string $costType,
        public float $amount,
        public string $currency,
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
        return 'mfg.accounting.wip_recognized';
    }

    public function toPayload(): array
    {
        return [
            'production_order_id' => $this->productionOrderId,
            'cost_type' => $this->costType,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
