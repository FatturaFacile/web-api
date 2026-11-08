<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * A project was closed. Other modules release what they held for it (assets, HR timesheets, service contracts); the
 * General Ledger books the final settlement: the work in progress still capitalised goes to the cost of revenue and the
 * residual contract asset is settled so that the revenue of the project equals what was invoiced.
 */
final readonly class ProjectClosureReadyEvent implements DomainEventInterface
{
    /**
     * @param  string|null  $wipReleaseAmount  capitalised cost still in work in progress, released at closure
     * @param  string|null  $contractAssetSettlement  signed: positive debits the contract asset and credits revenue
     */
    public function __construct(
        public string $projectId,
        public float|string $finalContractValue,
        public float|string $finalCost,
        public float|string $unbilledWipBalance,
        public float|string $retainedEarningsImpact,
        public string $fiscalYear,
        public string $tenantId,
        public string $companyId,
        public string $eventId = '',
        public string $occurredAt = '',
        public ?string $wipReleaseAmount = null,
        public ?string $contractAssetSettlement = null,
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
        return 'project.closure_ready';
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
            'final_contract_value' => $this->finalContractValue,
            'final_cost' => $this->finalCost,
            'unbilled_wip_balance' => $this->unbilledWipBalance,
            'retained_earnings_impact' => $this->retainedEarningsImpact,
            'fiscal_year' => $this->fiscalYear,
            'wip_release_amount' => $this->wipReleaseAmount,
            'contract_asset_settlement' => $this->contractAssetSettlement,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
