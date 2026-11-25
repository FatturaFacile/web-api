<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;

final readonly class OfflineVanDeltaSyncedEvent implements DomainEventInterface
{
    public function __construct(
        private string $vanWarehouseId,
        private string $tenantId,
        private int $processedCount,
        private int $conflictCount,
        private int $syncVersion,
    ) {}

    public function getEventName(): string
    {
        return 'van_sales.offline_delta_synced';
    }

    public function getAggregateType(): string
    {
        return 'van_warehouse';
    }

    public function getAggregateId(): string
    {
        return $this->vanWarehouseId;
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->tenantId;
    }

    public function toPayload(): array
    {
        return [
            'van_warehouse_id' => $this->vanWarehouseId,
            'processed_count' => $this->processedCount,
            'conflict_count' => $this->conflictCount,
            'sync_version' => $this->syncVersion,
        ];
    }
}
