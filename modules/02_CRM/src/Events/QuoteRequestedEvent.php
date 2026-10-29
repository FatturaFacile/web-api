<?php

declare(strict_types=1);

namespace Modules\CRM\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class QuoteRequestedEvent implements DomainEventInterface
{
    public function __construct(
        public string $opportunityId,
        public string $opportunityNumber,
        public string $contactId,
        public string $accountId,
        public float $amount,
        public string $currency,
        public array $lineItems,
        public string $actorUserId,
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
        return 'crm_opportunity';
    }

    public function getAggregateId(): string
    {
        return $this->opportunityId;
    }

    public function getEventName(): string
    {
        return 'crm.quote.requested';
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
