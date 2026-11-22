<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * A production routing was created or its operations were redefined.
 */
final class ProductionRoutingDefinedEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $aggregateId,
        public string $code,
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
        return 'mfg_routing';
    }

    /**
     * Aggregate id of the event.
     */
    public function getAggregateId(): string
    {
        return $this->aggregateId;
    }

    /**
     * Name of the event in the outbox.
     */
    public function getEventName(): string
    {
        return 'mfg.routing.defined';
    }

    /**
     * Payload of the event.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->aggregateId,
            'code' => $this->code,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
