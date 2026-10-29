<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class AssetImpairedEvent implements DomainEventInterface
{
    public function __construct(
        public string $valuationId,
        public string $assetId,
        public float $carryingAmountBefore,
        public float $recoverableAmount,
        public float $impairmentLossAmount,
        public float $carryingAmountAfter,
        public string $valuationDate,
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
        return 'eam_asset_valuation';
    }

    public function getAggregateId(): string
    {
        return $this->valuationId;
    }

    public function getEventName(): string
    {
        return 'eam.asset.impaired';
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
