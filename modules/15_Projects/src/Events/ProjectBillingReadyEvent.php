<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ProjectBillingReadyEvent implements DomainEventInterface
{
    public function __construct(
        public string $billingEventId,
        public string $projectId,
        public string $contactId,
        public float $billingAmount,
        public string $billingType, // milestone, progress, time_materials
        public ?string $milestoneId,
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
        return 'project';
    }

    public function getAggregateId(): string
    {
        return $this->projectId;
    }

    public function getEventName(): string
    {
        return 'project.billing_ready';
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
        return [
            'billing_event_id' => $this->billingEventId,
            'project_id' => $this->projectId,
            'contact_id' => $this->contactId,
            'billing_amount' => $this->billingAmount,
            'billing_type' => $this->billingType,
            'milestone_id' => $this->milestoneId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
