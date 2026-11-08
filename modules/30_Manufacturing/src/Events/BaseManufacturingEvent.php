<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * Base of the manufacturing domain events: event id, occurrence time, tenant and company.
 */
abstract class BaseManufacturingEvent implements DomainEventInterface
{
    public string $eventId;

    public string $occurredAt;

    public string $companyId;

    public function __construct(
        public string $tenantId,
        ?string $companyId = null,
        ?string $eventId = null,
        ?string $occurredAt = null
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid7();
        $this->occurredAt = $occurredAt ?? now()->toIso8601String();
        $this->companyId = $companyId ?? $tenantId;
    }

    /**
     * Identifier of the event.
     */
    public function getEventId(): string
    {
        return $this->eventId;
    }

    /**
     * When the event happened (ISO 8601).
     */
    public function getOccurredAt(): string
    {
        return $this->occurredAt;
    }

    /**
     * Tenant of the event.
     */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * Company of the event, the tenant when unset.
     */
    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    /**
     * Aggregate type of the event.
     */
    abstract public function getAggregateType(): string;

    /**
     * Aggregate id of the event.
     */
    abstract public function getAggregateId(): string;

    /**
     * Name of the event in the outbox.
     */
    abstract public function getEventName(): string;

    /**
     * Payload of the event.
     *
     * @return array<string, mixed>
     */
    abstract public function toPayload(): array;
}
