<?php

declare(strict_types=1);

namespace Modules\CRM\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class MeetingScheduledEvent implements DomainEventInterface
{
    public function __construct(
        public string $activityId,
        public string $subject,
        public string $relatedType,
        public string $relatedId,
        public string $scheduledAt,
        public ?int $durationMinutes,
        public ?string $location,
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
        return 'crm_activity';
    }

    public function getAggregateId(): string
    {
        return $this->activityId;
    }

    public function getEventName(): string
    {
        return 'crm.meeting.scheduled';
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
