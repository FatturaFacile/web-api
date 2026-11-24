<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * A component lot was consumed on a phase of the MES operator board (lot traceability).
 */
final class MesMaterialLotConsumedEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $phaseId,
        public string $consumptionId,
        public string $productionOrderId,
        public string $mfgItemId,
        public ?string $lotCode,
        public string $quantity,
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
        return 'mfg_mes_phase';
    }

    /**
     * Aggregate id of the event.
     */
    public function getAggregateId(): string
    {
        return $this->phaseId;
    }

    /**
     * Name of the event in the outbox.
     */
    public function getEventName(): string
    {
        return 'mfg.mes.material.lot_consumed';
    }

    /**
     * Payload of the event.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'phase_id' => $this->phaseId,
            'consumption_id' => $this->consumptionId,
            'production_order_id' => $this->productionOrderId,
            'mfg_item_id' => $this->mfgItemId,
            'lot_code' => $this->lotCode,
            'quantity' => $this->quantity,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
