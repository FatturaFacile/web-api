<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * Processed quantity came back from a subcontractor; the service cost accrued to GR/IR is what the supplier invoice
 * will clear. Amounts are decimal strings.
 */
final class SubcontractOrderReceivedEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $subcontractOrderId,
        public string $productionOrderId,
        public string $subcontractorContactId,
        public string $receivedQuantity,
        public string $serviceCost,
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
        return 'mfg_subcontract_order';
    }

    /**
     * Aggregate id of the event.
     */
    public function getAggregateId(): string
    {
        return $this->subcontractOrderId;
    }

    /**
     * Name of the event in the outbox.
     */
    public function getEventName(): string
    {
        return 'mfg.subcontract_order.received';
    }

    /**
     * Payload of the event.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'subcontract_order_id' => $this->subcontractOrderId,
            'production_order_id' => $this->productionOrderId,
            'subcontractor_contact_id' => $this->subcontractorContactId,
            'received_quantity' => $this->receivedQuantity,
            'service_cost' => $this->serviceCost,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
