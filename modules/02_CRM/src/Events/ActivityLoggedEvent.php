<?php

declare(strict_types=1);

namespace Modules\CRM\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * A call, meeting, task, note or email was logged on a CRM record. Engagement-based lead scoring and timelines react
 * to it.
 */
final readonly class ActivityLoggedEvent implements DomainEventInterface
{
    public function __construct(
        public string $activityId,
        public string $type,
        public ?string $relatedType,
        public ?string $relatedId,
        public ?string $assignedUserId,
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
        return 'crm_activity';
    }

    public function getAggregateId(): string
    {
        return $this->activityId;
    }

    public function getEventName(): string
    {
        return 'crm.activity.logged';
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
