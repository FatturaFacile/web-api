<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ProjectMilestoneCompletedEvent implements DomainEventInterface
{
    public function __construct(
        public string $milestoneId,
        public string $projectId,
        public string $milestoneName,
        public float $billingAmount,
        public string $completedAtDate,
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
        return 'project_milestone';
    }

    public function getAggregateId(): string
    {
        return $this->milestoneId;
    }

    public function getEventName(): string
    {
        return 'project.milestone.completed';
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
