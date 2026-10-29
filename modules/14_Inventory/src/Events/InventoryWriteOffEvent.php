<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class InventoryWriteOffEvent implements DomainEventInterface
{
    /**
     * @param  array<string, mixed>  $items
     */
    public function __construct(
        public string $writeOffId,
        public string $warehouseId,
        public string $reason,
        public float $totalWriteOffAmount,
        public array $items,
        public string $approvedByUserId,
        public string $companyId,
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
        return 'stock_write_off';
    }

    public function getAggregateId(): string
    {
        return $this->writeOffId;
    }

    public function getEventName(): string
    {
        return 'inventory.write_off';
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
            'write_off_id' => $this->writeOffId,
            'warehouse_id' => $this->warehouseId,
            'reason' => $this->reason,
            'total_write_off_amount' => $this->totalWriteOffAmount,
            'items' => $this->items,
            'approved_by_user_id' => $this->approvedByUserId,
            'company_id' => $this->companyId,
            'tenant_id' => $this->tenantId,
        ];
    }
}
