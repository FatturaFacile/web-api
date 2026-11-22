<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * The prepaid retainer / hour bank of a project crossed its low-balance threshold (Mod 081).
 */
final readonly class ProjectRetainerLowBalanceEvent implements DomainEventInterface
{
    public function __construct(
        public string $projectId,
        public string $billingContractId,
        public string $remainingHours,
        public string $remainingAmount,
        public string $remainingPercent,
        public string $thresholdPercent,
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
        return 'project.retainer_low_balance';
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
            'remaining_hours' => $this->remainingHours,
            'remaining_amount' => $this->remainingAmount,
            'remaining_percent' => $this->remainingPercent,
            'threshold_percent' => $this->thresholdPercent,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
