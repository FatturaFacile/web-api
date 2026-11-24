<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ProjectWipReadyEvent implements DomainEventInterface
{
    public function __construct(
        public string $projectId,
        public float $wipBalance,
        public float $costsIncurred,
        public float $billingsProgress,
        public string $wipAssetAccountCode,
        public string $asOfDate,
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
        return 'project.wip_ready';
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
            'wip_balance' => $this->wipBalance,
            'costs_incurred' => $this->costsIncurred,
            'billings_progress' => $this->billingsProgress,
            'wip_asset_account_code' => $this->wipAssetAccountCode,
            'as_of_date' => $this->asOfDate,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
