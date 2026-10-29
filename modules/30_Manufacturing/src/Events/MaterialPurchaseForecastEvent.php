<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

final class MaterialPurchaseForecastEvent extends BaseManufacturingEvent
{
    public function __construct(
        public array $purchaseRequirements,
        public float $totalEstimatedOutflow,
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
        return 'mfg_treasury_forecast';
    }

    public function getAggregateId(): string
    {
        return $this->tenantId;
    }

    public function getEventName(): string
    {
        return 'mfg.treasury.material_purchase_forecast';
    }

    public function toPayload(): array
    {
        return [
            'purchase_requirements' => $this->purchaseRequirements,
            'total_estimated_outflow' => $this->totalEstimatedOutflow,
            'currency' => $this->currency,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
