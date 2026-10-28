<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ProjectRevenueRecognizedEvent implements DomainEventInterface
{
    public function __construct(
        public string $revenueRecognitionId,
        public string $projectId,
        public string $accountingPeriodDate,
        public float $cumulativeActualCost,
        public float $costCompletionPercentage,
        public float $cumulativeRecognizedRevenue,
        public float $periodRevenueAdjustment,
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
        return 'project.revenue_recognized';
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
            'revenue_recognition_id' => $this->revenueRecognitionId,
            'project_id' => $this->projectId,
            'accounting_period_date' => $this->accountingPeriodDate,
            'cumulative_actual_cost' => $this->cumulativeActualCost,
            'cost_completion_percentage' => $this->costCompletionPercentage,
            'cumulative_recognized_revenue' => $this->cumulativeRecognizedRevenue,
            'period_revenue_adjustment' => $this->periodRevenueAdjustment,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
