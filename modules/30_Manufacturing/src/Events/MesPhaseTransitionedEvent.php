<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * A phase of the MES operator board changed state (taken over, setup, run, suspended, resumed, closed).
 */
final class MesPhaseTransitionedEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $phaseId,
        public string $productionOrderId,
        public string $productionOrderOperationId,
        public string $action,
        public string $status,
        public ?string $operatorId,
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
        return 'mfg.mes.phase.'.$this->action;
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
            'production_order_id' => $this->productionOrderId,
            'production_order_operation_id' => $this->productionOrderOperationId,
            'action' => $this->action,
            'status' => $this->status,
            'operator_id' => $this->operatorId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
