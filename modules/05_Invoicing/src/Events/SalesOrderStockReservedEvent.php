<?php

declare(strict_types=1);

namespace Modules\Invoicing\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class SalesOrderStockReservedEvent implements DomainEventInterface
{
    /**
     * @param  array<int, array<string, mixed>>  $reservedItems
     */
    public function __construct(
        public string $salesOrderId,
        public array $reservedItems,
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
        return 'sales_order';
    }

    public function getAggregateId(): string
    {
        return $this->salesOrderId;
    }

    public function getEventName(): string
    {
        return 'sales.order.stock_reserved';
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function toPayload(): array
    {
        return get_object_vars($this);
    }
}
