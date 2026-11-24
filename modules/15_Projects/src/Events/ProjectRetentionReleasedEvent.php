<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * Guarantee retention of a project billing contract was released (in full or in part): receivables and treasury settle
 * the withheld amount (Mod 081).
 */
final readonly class ProjectRetentionReleasedEvent implements DomainEventInterface
{
    public function __construct(
        public string $projectId,
        public string $billingContractId,
        public string $releasedAmount,
        public string $remainingRetention,
        public string $releaseDate,
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
        return 'project.retention_released';
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
            'billing_contract_id' => $this->billingContractId,
            'released_amount' => $this->releasedAmount,
            'remaining_retention' => $this->remainingRetention,
            'release_date' => $this->releaseDate,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
