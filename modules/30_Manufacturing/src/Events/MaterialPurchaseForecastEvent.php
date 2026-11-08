<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * Monthly projection of the purchases suggested by MRP, at standard cost, for Treasury.
 */
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

    /**
     * Aggregate type of the event.
     */
    public function getAggregateType(): string
    {
        return 'mfg_treasury_forecast';
    }

    /**
     * Aggregate id of the event.
     */
    public function getAggregateId(): string
    {
        return $this->tenantId;
    }

    /**
     * Name of the event in the outbox.
     */
    public function getEventName(): string
    {
        return 'mfg.treasury.material_purchase_forecast';
    }

    /**
     * Payload of the event.
     *
     * @return array<string, mixed>
     */
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
