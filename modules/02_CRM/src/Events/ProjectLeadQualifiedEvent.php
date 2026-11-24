<?php

declare(strict_types=1);

namespace Modules\CRM\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ProjectLeadQualifiedEvent implements DomainEventInterface
{
    public function __construct(
        public string $leadId,
        public string $leadName,
        public ?string $companyName,
        public float $estimatedBudget,
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
        return 'crm_lead';
    }

    public function getAggregateId(): string
    {
        return $this->leadId;
    }

    public function getEventName(): string
    {
        return 'crm.project_lead.qualified';
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
