<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * A production order was closed: its WIP balance was settled to the variance accounts; amounts are decimal strings.
 */
final class ProductionOrderClosedEvent extends BaseManufacturingEvent
{
    /**
     * @param  array<string, string>  $variances  cost element => variance
     */
    public function __construct(
        public string $productionOrderId,
        public string $orderNumber,
        public string $receivedQuantity,
        public string $standardCostOfOutput,
        public string $actualCost,
        public array $variances,
        public ?string $journalEntryId,
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
        return 'mfg.production_order.closed';
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
            'order_number' => $this->orderNumber,
            'received_quantity' => $this->receivedQuantity,
            'standard_cost_of_output' => $this->standardCostOfOutput,
            'actual_cost' => $this->actualCost,
            'variances' => $this->variances,
            'journal_entry_id' => $this->journalEntryId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
