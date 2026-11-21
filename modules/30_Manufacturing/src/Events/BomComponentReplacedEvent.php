<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * A component was replaced by another across released BOMs: product costing (Mod 067) and the production planning
 * (Mod 064) refresh the affected products from it.
 */
final class BomComponentReplacedEvent extends BaseManufacturingEvent
{
    /**
     * @param  list<string>  $proposedBomIds  the new revisions waiting for approval
     * @param  list<string>  $engineeringChangeOrderIds
     */
    public function __construct(
        public string $obsoleteItemId,
        public string $substituteItemId,
        public array $proposedBomIds,
        public array $engineeringChangeOrderIds,
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
        return 'mfg_item';
    }

    /**
     * Aggregate id of the event.
     */
    public function getAggregateId(): string
    {
        return $this->obsoleteItemId;
    }

    /**
     * Name of the event in the outbox.
     */
    public function getEventName(): string
    {
        return 'mfg.bom.component_replaced';
    }

    /**
     * Payload of the event.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'obsolete_item_id' => $this->obsoleteItemId,
            'substitute_item_id' => $this->substituteItemId,
            'proposed_bom_ids' => $this->proposedBomIds,
            'engineering_change_order_ids' => $this->engineeringChangeOrderIds,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
