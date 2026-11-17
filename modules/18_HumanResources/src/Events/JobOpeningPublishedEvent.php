<?php

declare(strict_types=1);

namespace Modules\HumanResources\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * A job opening was published on the careers page or internally.
 */
final readonly class JobOpeningPublishedEvent implements DomainEventInterface
{
    public function __construct(
        public string $jobOpeningId,
        public string $reference,
        public string $positionId,
        public string $status,
        public int $openingsCount,
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
        return 'hr_job_opening';
    }

    public function getAggregateId(): string
    {
        return $this->jobOpeningId;
    }

    public function getEventName(): string
    {
        return 'hr.recruiting.job_opening_published';
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
        return get_object_vars($this);
    }
}
