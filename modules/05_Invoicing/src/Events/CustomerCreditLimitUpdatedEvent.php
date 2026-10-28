<?php

declare(strict_types=1);

namespace Modules\Invoicing\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class CustomerCreditLimitUpdatedEvent implements DomainEventInterface
{
    public function __construct(
        public string $creditProfileId,
        public string $customerId,
        public float $oldLimit,
        public float $newLimit,
        public string $updatedBy,
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
        return 'customer_credit_profile';
    }

    public function getAggregateId(): string
    {
        return $this->creditProfileId;
    }

    public function getEventName(): string
    {
        return 'sales.customer_credit.limit_updated';
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
