<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use Illuminate\Support\Carbon;
use Modules\Budgeting\Contracts\LedgerLookup;
use Modules\Budgeting\Domain\Availability;
use Modules\Budgeting\Models\BudgetCommitment;
use Modules\Budgeting\Models\BudgetLine;

/**
 * Available budget = budget - (actual costs + open commitments), over the fiscal year of a date. The budget is the
 * governing one (see BaselineResolver), the actuals are the posted ledger amounts of the expense accounts the budget
 * covers, and the commitments are the open requisitions and purchase orders. Each dimension that is given narrows the
 * scope (a cost centre, a project, an account); a dimension left out is not filtered. Amounts are net of tax and in the
 * currency of the budget.
 */
final readonly class AvailabilityService
{
    public function __construct(private BaselineResolver $baselines, private LedgerLookup $ledger) {}

    /**
     * A purchase order that comes from a requisition replaces the requisition commitment up to its own amount: pass
     * the requisition and the amount of the order so the same spend is not counted twice.
     */
    public function forScope(string $tenantId, Carbon $date, ?string $accountId, ?string $costCenterId, ?string $projectId, ?string $excludeSourceType = null, ?string $excludeSourceId = null, ?string $replacesRequisitionId = null, string $replacedAmount = '0.00'): Availability
    {
        $version = $this->baselines->forDate($tenantId, $date);
        $currency = $this->baselines->currency($tenantId, $version);
        $commitments = $this->openCommitments($tenantId, $version->fiscal_year ?? $date->year, $accountId, $costCenterId, $projectId, $excludeSourceType, $excludeSourceId);

        if ($replacesRequisitionId !== null) {
            $requisition = BudgetCommitment::query()->where('tenant_id', $tenantId)->where('source_type', BudgetCommitment::Requisition)->where('source_id', $replacesRequisitionId)->first();
            $credit = $requisition === null ? '0.00' : $requisition->openAmount();
            $credit = bccomp($credit, $replacedAmount, 2) > 0 ? $replacedAmount : $credit;
            $commitments = bcsub($commitments, $credit, 2);
            $commitments = bccomp($commitments, '0', 2) < 0 ? '0.00' : $commitments;
        }

        if ($version === null) {
            return new Availability(null, $date->year, false, $currency, '0.00', '0.00', $commitments);
        }

        $lines = BudgetLine::query()
            ->where('version_id', $version->id)
            ->when($accountId !== null, static fn ($query) => $query->where('account_id', $accountId))
            ->when($costCenterId !== null, static fn ($query) => $query->where('cost_center_id', $costCenterId))
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->get(['account_id', 'amount']);

        $kinds = $this->ledger->accounts($tenantId, $lines->pluck('account_id')->unique()->values()->all());
        $spendAccounts = array_keys(array_filter($kinds, static fn (array $account): bool => $account['kind'] !== 'revenue'));
        $budget = '0.00';

        foreach ($lines as $line) {
            if (in_array($line->account_id, $spendAccounts, true)) {
                $budget = bcadd($budget, (string) $line->amount, 2);
            }
        }

        $actual = '0.00';

        if ($spendAccounts !== []) {
            foreach ($this->ledger->monthlyActuals($tenantId, BudgetPeriods::periodStart($version, 1), BudgetPeriods::periodEnd($version, BudgetPeriods::Count)) as $row) {
                if (! in_array($row['account_id'], $spendAccounts, true)
                    || ($costCenterId !== null && $row['cost_center_id'] !== $costCenterId)
                    || ($projectId !== null && $row['project_id'] !== $projectId)) {
                    continue;
                }

                $actual = bcadd($actual, $row['amount'], 2);
            }
        }

        return new Availability($version->id, $version->fiscal_year, $lines->isNotEmpty(), $currency, $budget, $actual, $commitments);
    }

    private function openCommitments(string $tenantId, int $fiscalYear, ?string $accountId, ?string $costCenterId, ?string $projectId, ?string $excludeType, ?string $excludeId): string
    {
        $total = '0.00';

        $rows = BudgetCommitment::query()
            ->where('tenant_id', $tenantId)
            ->where('fiscal_year', $fiscalYear)
            ->where('status', BudgetCommitment::Open)
            ->when($accountId !== null, static fn ($query) => $query->where('account_id', $accountId))
            ->when($costCenterId !== null, static fn ($query) => $query->where('cost_center_id', $costCenterId))
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->get();

        foreach ($rows as $commitment) {
            if ($excludeType !== null && $commitment->source_type === $excludeType && $commitment->source_id === $excludeId) {
                continue;
            }

            $total = bcadd($total, $commitment->openAmount(), 2);
        }

        return $total;
    }
}
