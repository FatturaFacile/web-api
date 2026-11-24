<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * An approved engineering change order was implemented: a new BOM revision is effective from its effective date.
 */
final class EngineeringChangeImplementedEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $engineeringChangeOrderId,
        public string $ecoNumber,
        public string $previousBomId,
        public string $resultingBomId,
        public string $effectiveDate,
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
        return 'mfg_engineering_change_order';
    }

    /**
     * Aggregate id of the event.
     */
    public function getAggregateId(): string
    {
        return $this->engineeringChangeOrderId;
    }

    /**
     * Name of the event in the outbox.
     */
    public function getEventName(): string
    {
        return 'mfg.engineering_change.implemented';
    }

    /**
     * Payload of the event.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'engineering_change_order_id' => $this->engineeringChangeOrderId,
            'eco_number' => $this->ecoNumber,
            'previous_bom_id' => $this->previousBomId,
            'resulting_bom_id' => $this->resultingBomId,
            'effective_date' => $this->effectiveDate,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
