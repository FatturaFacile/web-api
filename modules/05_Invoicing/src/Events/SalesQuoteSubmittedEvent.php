<?php

declare(strict_types=1);

namespace Modules\Invoicing\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class SalesQuoteSubmittedEvent implements DomainEventInterface
{
    public function __construct(
        public string $quoteId,
        public string $quoteNumber,
        public string $customerId,
        public float $totalGross,
        public string $currency,
        public string $userId,
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
        return 'sales_quote';
    }

    public function getAggregateId(): string
    {
        return $this->quoteId;
    }

    public function getEventName(): string
    {
        return 'invoicing.sales_quote.submitted';
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
