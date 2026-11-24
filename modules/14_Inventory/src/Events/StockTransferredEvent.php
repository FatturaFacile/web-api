<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class StockTransferredEvent implements DomainEventInterface
{
    public function __construct(
        public string $movementId,
        public string $stockItemId,
        public ?string $sourceBinId,
        public ?string $destinationBinId,
        public float $quantity,
        public string $movementType,
        public float $totalValuation,
        public string $tenantId = '',
        public string $companyId = '',
        public string $eventId = '',
        public string $occurredAt = ''
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'stock_movement';
    }

    public function getAggregateId(): string
    {
        return $this->movementId;
    }

    public function getEventName(): string
    {
        return 'inventory.stock_transferred';
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
            'movement_id' => $this->movementId,
            'stock_item_id' => $this->stockItemId,
            'source_bin_id' => $this->sourceBinId,
            'destination_bin_id' => $this->destinationBinId,
            'quantity' => $this->quantity,
            'movement_type' => $this->movementType,
            'total_valuation' => $this->totalValuation,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
