<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * A new BOM revision was copied from an existing one.
 */
final class BOMRevisedEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $bomId,
        public string $bomNumber,
        public string $newRevision,
        public int $version,
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
        return 'mfg.bom.revised';
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
            'new_revision' => $this->newRevision,
            'version' => $this->version,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
