<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * Lightweight notification to the MES Core that reference data changed. It carries no business data: the MES Core pulls
 * the entity from the `mes-sync` endpoint, so there is a single representation of every resource.
 */
abstract class MesNotificationEvent extends BaseManufacturingEvent
{
    /** Version of the notification payload. */
    public const int SchemaVersion = 1;

    public function __construct(
        public string $resource,
        public string $entityId,
        public int $changeSeq,
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
        return 'mfg_mes_'.str_replace('-', '_', $this->resource);
    }

    /**
     * Aggregate id of the event.
     */
    public function getAggregateId(): string
    {
        return $this->entityId;
    }

    /**
     * Payload of the event.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'event_id' => $this->eventId,
            'tenant_id' => $this->tenantId,
            'resource' => $this->resource,
            'entity_id' => $this->entityId,
            'change_seq' => $this->changeSeq,
            'schema_version' => self::SchemaVersion,
        ];
    }
}
