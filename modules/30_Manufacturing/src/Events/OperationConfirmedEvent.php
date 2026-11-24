<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * An operation of a production order was confirmed with actual times and quantities; amounts are decimal strings.
 */
final class OperationConfirmedEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $confirmationId,
        public string $productionOrderId,
        public string $productionOrderOperationId,
        public string $goodQuantity,
        public string $scrapQuantity,
        public string $laborCost,
        public string $overheadCost,
        public bool $isFinal,
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
        return 'mfg.production_order.operation_confirmed';
    }

    /**
     * Payload of the event.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'confirmation_id' => $this->confirmationId,
            'production_order_id' => $this->productionOrderId,
            'production_order_operation_id' => $this->productionOrderOperationId,
            'good_quantity' => $this->goodQuantity,
            'scrap_quantity' => $this->scrapQuantity,
            'labor_cost' => $this->laborCost,
            'overhead_cost' => $this->overheadCost,
            'is_final' => $this->isFinal,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
