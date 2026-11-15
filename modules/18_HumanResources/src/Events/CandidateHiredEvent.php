<?php

declare(strict_types=1);

namespace Modules\HumanResources\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * A candidate was hired and converted into an employee.
 */
final readonly class CandidateHiredEvent implements DomainEventInterface
{
    public function __construct(
        public string $applicationId,
        public string $jobOpeningId,
        public string $employeeId,
        public string $positionId,
        public string $hireDate,
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
        return 'hr_candidate_application';
    }

    public function getAggregateId(): string
    {
        return $this->applicationId;
    }

    public function getEventName(): string
    {
        return 'hr.recruiting.candidate_hired';
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
