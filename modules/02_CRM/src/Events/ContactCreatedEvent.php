<?php

declare(strict_types=1);

namespace Modules\CRM\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * A contact (customer, supplier or person) has been created, whichever path created it (API, import, conversion of a
 * lead, party master link). Raised by ContactCreatedObserver, so exactly once per contact.
 */
final readonly class ContactCreatedEvent implements DomainEventInterface
{
    public function __construct(
        public string $contactId,
        public string $contactType,
        public ?string $companyName,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $email,
        public ?string $country,
        public ?string $vatNumber,
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
        return 'contact';
    }

    public function getAggregateId(): string
    {
        return $this->contactId;
    }

    public function getEventName(): string
    {
        return 'crm.contact.created';
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
