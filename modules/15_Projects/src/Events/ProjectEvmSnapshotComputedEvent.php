<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * A financial snapshot of a project was computed (Mod 082): the project-level EVM figures and margin, for the
 * dashboards and the downstream consumers.
 */
final readonly class ProjectEvmSnapshotComputedEvent implements DomainEventInterface
{
    /**
     * @param  array<string, string|null>  $figures
     */
    public function __construct(
        public string $projectId,
        public string $snapshotDate,
        public array $figures,
        public string $trigger,
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
        return 'project.evm_snapshot_computed';
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
            'snapshot_date' => $this->snapshotDate,
            'trigger' => $this->trigger,
            'figures' => $this->figures,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
