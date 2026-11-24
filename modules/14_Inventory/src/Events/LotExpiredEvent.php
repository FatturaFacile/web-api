<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class LotExpiredEvent implements DomainEventInterface
{
    public function __construct(
        public string $lotId,
        public string $lotNumber,
        public string $stockItemId,
        public string $expiryDate,
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
        return 'stock_lot';
    }

    public function getAggregateId(): string
    {
        return $this->lotId;
    }

    public function getEventName(): string
    {
        return 'inventory.lot_expired';
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
            'lot_id' => $this->lotId,
            'lot_number' => $this->lotNumber,
            'stock_item_id' => $this->stockItemId,
            'expiry_date' => $this->expiryDate,
            'company_id' => $this->companyId,
            'tenant_id' => $this->tenantId,
        ];
    }
}
