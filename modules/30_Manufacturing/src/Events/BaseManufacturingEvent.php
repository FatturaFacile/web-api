<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

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

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getOccurredAt(): string
    {
        return $this->occurredAt;
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    abstract public function getAggregateType(): string;

    abstract public function getAggregateId(): string;

    abstract public function getEventName(): string;

    abstract public function toPayload(): array;
}
