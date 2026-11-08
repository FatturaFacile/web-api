<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * Monthly projection of the inventory inflow from production, at standard cost, for Treasury.
 */
final class InventoryForecastEvent extends BaseManufacturingEvent
{
    public function __construct(
        public array $projectedInventoryValuation,
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
        return 'mfg.treasury.inventory_forecast';
    }

    /**
     * Payload of the event.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'projected_inventory_valuation' => $this->projectedInventoryValuation,
            'currency' => $this->currency,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
