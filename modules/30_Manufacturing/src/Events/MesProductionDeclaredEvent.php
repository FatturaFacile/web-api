<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * Good and scrapped pieces were declared on a phase of the MES operator board.
 */
final class MesProductionDeclaredEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $phaseId,
        public string $declarationId,
        public string $productionOrderId,
        public string $goodQuantity,
        public string $scrapQuantity,
        public ?string $defectReason,
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
        return 'mfg.mes.production.declared';
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
            'declaration_id' => $this->declarationId,
            'production_order_id' => $this->productionOrderId,
            'good_quantity' => $this->goodQuantity,
            'scrap_quantity' => $this->scrapQuantity,
            'defect_reason' => $this->defectReason,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
