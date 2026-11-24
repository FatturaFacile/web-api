<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ProjectAccrualReadyEvent implements DomainEventInterface
{
    public function __construct(
        public string $projectId,
        public float $accrualAmount,
        public string $accrualType, // cost_accrual, unbilled_revenue
        public string $debitAccountCode,
        public string $creditAccountCode,
        public string $accountingPeriod,
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
        return 'project.accrual_ready';
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
            'accrual_amount' => $this->accrualAmount,
            'accrual_type' => $this->accrualType,
            'debit_account_code' => $this->debitAccountCode,
            'credit_account_code' => $this->creditAccountCode,
            'accounting_period' => $this->accountingPeriod,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
