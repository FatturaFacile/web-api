<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * The ledger effect of one revenue recognition run of a project.
 *
 * periodRevenueAdjustment is the change of the contract asset for the period (recognised revenue minus billed revenue,
 * against the previous run): positive means debit contract asset and credit revenue, negative the reverse, so that the
 * revenue in the result equals the recognised revenue whatever was already invoiced. periodCostOfRevenue is the
 * capitalised cost released from work in progress to the cost of revenue in the same run.
 */
final readonly class ProjectRevenueRecognitionReadyEvent implements DomainEventInterface
{
    public function __construct(
        public string $recognitionId,
        public string $projectId,
        public float|string $cumulativeRecognizedRevenue,
        public float|string $periodRevenueAdjustment,
        public float|string $costCompletionPercentage,
        public string $accountingPeriod,
        public string $debitAccountCode,
        public string $creditAccountCode,
        public string $tenantId,
        public string $companyId,
        public string $eventId = '',
        public string $occurredAt = '',
        public string $periodCostOfRevenue = '0',
        public string $recognitionMethod = 'percentage_of_completion',
        public string $costOfRevenueAccountCode = '5000',
        public string $workInProgressAccountCode = '1250',
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
        return 'project.revenue_recognition.ready';
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
