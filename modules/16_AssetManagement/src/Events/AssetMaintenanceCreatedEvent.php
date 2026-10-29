<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class AssetMaintenanceCreatedEvent implements DomainEventInterface
{
    public function __construct(
        public string $workOrderId,
        public string $workOrderNumber,
        public string $assetId,
        public string $maintenanceType,
        public string $priority,
        public float $totalCost,
        public string $scheduledDate,
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
        return 'eam_maintenance_work_order';
    }

    public function getAggregateId(): string
    {
        return $this->workOrderId;
    }

    public function getEventName(): string
    {
        return 'eam.maintenance.created';
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
