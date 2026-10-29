<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ProjectPhaseCompletedEvent implements DomainEventInterface
{
    public function __construct(
        public string $projectId,
        public string $phaseId,
        public string $phaseName,
        public int $sequenceOrder,
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
        return 'project_phase';
    }

    public function getAggregateId(): string
    {
        return $this->phaseId;
    }

    public function getEventName(): string
    {
        return 'project.phase_completed';
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
            'project_id' => $this->projectId,
            'phase_id' => $this->phaseId,
            'phase_name' => $this->phaseName,
            'sequence_order' => $this->sequenceOrder,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
