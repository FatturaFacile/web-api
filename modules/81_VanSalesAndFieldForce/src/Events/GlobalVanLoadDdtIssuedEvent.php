<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;

final readonly class GlobalVanLoadDdtIssuedEvent implements DomainEventInterface
{
    public function __construct(
        private string $manifestId,
        private string $tenantId,
        private string $vanWarehouseId,
        private string $globalDdtNumber,
        private float $totalNetValue,
    ) {}

    public function getEventName(): string
    {
        return 'van_sales.global_load_ddt_issued';
    }

    public function getAggregateType(): string
    {
        return 'van_load_manifest';
    }

    public function getAggregateId(): string
    {
        return $this->manifestId;
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
            'manifest_id' => $this->manifestId,
            'van_warehouse_id' => $this->vanWarehouseId,
            'global_ddt_number' => $this->globalDdtNumber,
            'total_net_value' => $this->totalNetValue,
        ];
    }
}
