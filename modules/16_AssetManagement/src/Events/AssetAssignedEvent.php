<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class AssetAssignedEvent implements DomainEventInterface
{
    public function __construct(
        public string $assignmentId,
        public string $assetId,
        public string $assigneeType,
        public ?string $assigneeId,
        public string $assigneeName,
        public string $assignedAt,
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
        return 'eam_asset_assignment';
    }

    public function getAggregateId(): string
    {
        return $this->assignmentId;
    }

    public function getEventName(): string
    {
        return 'eam.asset.assigned';
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
