<?php

declare(strict_types=1);

namespace Modules\Treasury\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class LiquidityAlertEvent implements DomainEventInterface
{
    public function __construct(
        public string $alertId,
        public string $alertType,
        public string $severity,
        public string $title,
        public float $thresholdValue,
        public float $actualValue,
        public string $tenantId,
        public string $companyId,
        public string $eventId = '',
        public string $occurredAt = ''
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'treasury_alert';
    }

    public function getAggregateId(): string
    {
        return $this->alertId;
    }

    public function getEventName(): string
    {
        return 'treasury.liquidity.alert';
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function toPayload(): array
    {
        return get_object_vars($this);
    }
}
