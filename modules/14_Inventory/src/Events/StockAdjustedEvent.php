<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class StockAdjustedEvent implements DomainEventInterface
{
    public function __construct(
        public string $adjustmentId,
        public string $warehouseId,
        public float $netValuationChange,
        public string $status,
        public string $companyId,
        public ?string $userId = null,
        public string $tenantId = '',
        public string $eventId = '',
        public string $occurredAt = ''
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'inventory_adjustment';
    }

    public function getAggregateId(): string
    {
        return $this->adjustmentId;
    }

    public function getEventName(): string
    {
        return 'inventory.stock_adjusted';
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
        return [
            'adjustment_id' => $this->adjustmentId,
            'warehouse_id' => $this->warehouseId,
            'net_valuation_change' => $this->netValuationChange,
            'status' => $this->status,
            'company_id' => $this->companyId,
            'user_id' => $this->userId,
        ];
    }
}
