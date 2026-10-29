<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class AssetCashImpactEvent implements DomainEventInterface
{
    public function __construct(
        public string $assetId,
        public string $impactType,
        public string $cashFlowDirection,
        public float $amount,
        public string $currency,
        public string $effectiveDate,
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
        return 'eam_fixed_asset';
    }

    public function getAggregateId(): string
    {
        return $this->assetId;
    }

    public function getEventName(): string
    {
        return 'treasury.forecast.asset_cash_impact';
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getOccurredAt(): string
    {
        return $this->occurredAt !== '' ? $this->occurredAt : now()->toIso8601String();
    }

    public function toPayload(): array
    {
        return get_object_vars($this);
    }
}
