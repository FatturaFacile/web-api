<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * A BOM revision was proposed through an engineering change order and waits for approval (the approval workflow of
 * Mod 006 listens to it); the payload carries the structural diff summary.
 */
final class BomEcoSubmittedEvent extends BaseManufacturingEvent
{
    /**
     * @param  array{added: int, removed: int, modified: int, unchanged: int}  $diffSummary
     */
    public function __construct(
        public string $engineeringChangeOrderId,
        public string $ecoNumber,
        public string $sourceBomId,
        public string $proposedBomId,
        public array $diffSummary,
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
        return 'mfg.bom.eco_submitted';
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
            'source_bom_id' => $this->sourceBomId,
            'proposed_bom_id' => $this->proposedBomId,
            'diff_summary' => $this->diffSummary,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
