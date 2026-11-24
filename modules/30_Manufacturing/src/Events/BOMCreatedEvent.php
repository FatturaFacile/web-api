<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * A BOM revision was created (as a draft).
 */
final class BOMCreatedEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $bomId,
        public string $bomNumber,
        public string $mfgItemId,
        public string $revision,
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
        return 'mfg_bom';
    }

    /**
     * Aggregate id of the event.
     */
    public function getAggregateId(): string
    {
        return $this->bomId;
    }

    /**
     * Name of the event in the outbox.
     */
    public function getEventName(): string
    {
        return 'mfg.bom.created';
    }

    /**
     * Payload of the event.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'bom_id' => $this->bomId,
            'bom_number' => $this->bomNumber,
            'mfg_item_id' => $this->mfgItemId,
            'revision' => $this->revision,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
