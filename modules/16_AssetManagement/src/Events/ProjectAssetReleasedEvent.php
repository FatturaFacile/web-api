<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ProjectAssetReleasedEvent implements DomainEventInterface
{
    public function __construct(
        public string $allocationId,
        public string $assetId,
        public string $projectId,
        public float $totalAttributedCost,
        public string $releasedAt,
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
        return 'eam_project_asset_allocation';
    }

    public function getAggregateId(): string
    {
        return $this->allocationId;
    }

    public function getEventName(): string
    {
        return 'eam.project_asset.released';
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
