<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;

final readonly class EndOfDayVanSettlementCompletedEvent implements DomainEventInterface
{
    public function __construct(
        private string $settlementId,
        private string $tenantId,
        private string $vanWarehouseId,
        private string $settlementNumber,
        private float $totalRemittanceAmount,
    ) {}

    public function getEventName(): string
    {
        return 'van_sales.eod_settlement_completed';
    }

    public function getAggregateType(): string
    {
        return 'van_eod_settlement';
    }

    public function getAggregateId(): string
    {
        return $this->settlementId;
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
            'settlement_id' => $this->settlementId,
            'van_warehouse_id' => $this->vanWarehouseId,
            'settlement_number' => $this->settlementNumber,
            'total_remittance_amount' => $this->totalRemittanceAmount,
        ];
    }
}
