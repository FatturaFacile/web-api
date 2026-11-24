<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class StockReceivedEvent implements DomainEventInterface
{
    public function __construct(
        public string $warehouseId,
        public string $stockItemId,
        public float $quantity,
        public ?string $destinationBinId,
        public ?string $lotNumber,
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
        return 'stock_quant';
    }

    public function getAggregateId(): string
    {
        return $this->stockItemId;
    }

    public function getEventName(): string
    {
        return 'inventory.stock_received';
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
            'warehouse_id' => $this->warehouseId,
            'stock_item_id' => $this->stockItemId,
            'quantity' => $this->quantity,
            'destination_bin_id' => $this->destinationBinId,
            'lot_number' => $this->lotNumber,
            'company_id' => $this->companyId,
            'tenant_id' => $this->tenantId,
        ];
    }
}
