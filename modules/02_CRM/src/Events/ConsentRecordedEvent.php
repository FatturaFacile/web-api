<?php

declare(strict_types=1);

namespace Modules\CRM\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * A GDPR consent was granted or withdrawn. Downstream channels (newsletters, integrations) must stop or start
 * contacting the subject accordingly.
 */
final readonly class ConsentRecordedEvent implements DomainEventInterface
{
    public function __construct(
        public string $consentId,
        public ?string $contactId,
        public ?string $leadId,
        public string $channel,
        public string $purpose,
        public string $status,
        public string $source,
        public string $tenantId,
        public string $companyId,
        public string $eventId = '',
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'crm_consent';
    }

    public function getAggregateId(): string
    {
        return $this->consentId;
    }

    public function getEventName(): string
    {
        return 'crm.consent.recorded';
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return get_object_vars($this);
    }
}
