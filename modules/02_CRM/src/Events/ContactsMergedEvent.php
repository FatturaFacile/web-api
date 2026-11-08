<?php

declare(strict_types=1);

namespace Modules\CRM\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * Two contacts of the party master were merged: every module that caches contact ids must replace the merged id
 * with the survivor.
 */
final readonly class ContactsMergedEvent implements DomainEventInterface
{
    /**
     * @param  array<string, int>  $repointed  rows repointed per table.column
     */
    public function __construct(
        public string $mergeId,
        public string $survivorContactId,
        public string $mergedContactId,
        public array $repointed,
        public ?string $actorUserId,
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
        return 'contact';
    }

    public function getAggregateId(): string
    {
        return $this->survivorContactId;
    }

    public function getEventName(): string
    {
        return 'crm.contacts.merged';
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
