<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class SerialAssignedEvent implements DomainEventInterface
{
    public function __construct(
        public string $serialId,
        public string $serialNumber,
        public string $stockItemId,
        public string $assignedContext,
        public string $contextId,
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
        return 'stock_serial';
    }

    public function getAggregateId(): string
    {
        return $this->serialId;
    }

    public function getEventName(): string
    {
        return 'inventory.serial_assigned';
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
            'serial_id' => $this->serialId,
            'serial_number' => $this->serialNumber,
            'stock_item_id' => $this->stockItemId,
            'assigned_context' => $this->assignedContext,
            'context_id' => $this->contextId,
            'company_id' => $this->companyId,
            'tenant_id' => $this->tenantId,
        ];
    }
}
