<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use Modules\Budgeting\Contracts\LedgerLookup;
use Modules\Budgeting\Models\BudgetLine;
use Modules\Budgeting\Models\BudgetVersion;

/**
 * Budget versus actual. The budget is summed over the periods up to the chosen one; the actuals come from the ledger
 * (through the ledger lookup) for the accounts the version budgets, over the same periods. The variance is actual minus
 * budget: for an expense it is favourable when negative, for a revenue when positive.
 */
final readonly class VarianceService
{
    public const array GroupBy = ['account', 'cost_center', 'project', 'period'];

    public function __construct(private LedgerLookup $ledger) {}

    /**
     * @return array{version_id: string, through_period: int, group_by: string, rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function variance(BudgetVersion $version, int $throughPeriod, string $groupBy): array
    {
        $throughPeriod = max(1, min(BudgetPeriods::Count, $throughPeriod));
        $lines = BudgetLine::query()->where('version_id', $version->id)->where('period', '<=', $throughPeriod)->get();
        $budgetedAccounts = BudgetLine::query()->where('version_id', $version->id)->distinct()->pluck('account_id')->all();

        $actuals = array_values(array_filter(
            $this->ledger->monthlyActuals($version->tenant_id, BudgetPeriods::periodStart($version, 1), BudgetPeriods::periodEnd($version, $throughPeriod)),
            static fn (array $row): bool => in_array($row['account_id'], $budgetedAccounts, true),
        ));

        $accounts = $this->ledger->accounts($version->tenant_id, $budgetedAccounts);
        $groups = [];

        foreach ($lines as $line) {
            $key = $this->groupKey($groupBy, $line->account_id, $line->cost_center_id, $line->project_id, $line->period);
            $groups[$key] ??= $this->blank($line->account_id, $line->cost_center_id, $line->project_id, $line->period);
            $groups[$key]['budget'] = bcadd($groups[$key]['budget'], (string) $line->amount, 2);
        }

        foreach ($actuals as $row) {
            $period = BudgetPeriods::periodOfMonth($version, $row['month']);

            if ($period === null || $period > $throughPeriod) {
                continue;
            }

            $key = $this->groupKey($groupBy, $row['account_id'], $row['cost_center_id'], $row['project_id'], $period);
            $groups[$key] ??= $this->blank($row['account_id'], $row['cost_center_id'], $row['project_id'], $period);
            $groups[$key]['actual'] = bcadd($groups[$key]['actual'], $row['amount'], 2);
        }

        $labels = $this->labels($version->tenant_id, $groupBy, $groups, $accounts);
        $rows = [];
        $totals = ['budget' => '0.00', 'actual' => '0.00'];
        $adverse = '0.00';
        $favorable = '0.00';

        foreach ($groups as $key => $group) {
            $variance = bcsub($group['actual'], $group['budget'], 2);
            $kind = $groupBy === 'account' ? ($accounts[$group['account_id']]['kind'] ?? 'other') : 'mixed';
            $isFavorable = $kind === 'revenue' ? bccomp($variance, '0', 2) >= 0 : bccomp($variance, '0', 2) <= 0;
            $totals['budget'] = bcadd($totals['budget'], $group['budget'], 2);
            $totals['actual'] = bcadd($totals['actual'], $group['actual'], 2);

            $rows[] = [
                'key' => $this->displayKey($groupBy, $group),
                'label' => $labels[$key]['label'] ?? '',
                'code' => $labels[$key]['code'] ?? null,
                'budget' => $group['budget'],
                'actual' => $group['actual'],
                'variance' => $variance,
                'variance_percent' => bccomp($group['budget'], '0', 2) === 0 ? null : bcround(bcmul(bcdiv($variance, $group['budget'], 8), '100', 8), 1),
                'is_favorable' => $isFavorable,
                'status' => match (bccomp($variance, '0', 2)) {
                    1 => 'over',
                    -1 => 'under',
                    default => 'on_budget',
                },
            ];

            if ($kind !== 'mixed') {
                if ($isFavorable) {
                    $favorable = bcadd($favorable, bcmul($variance, bccomp($variance, '0', 2) < 0 ? '-1' : '1', 2), 2);
                } else {
                    $adverse = bcadd($adverse, bcmul($variance, bccomp($variance, '0', 2) < 0 ? '-1' : '1', 2), 2);
                }
            }
        }

        usort($rows, $groupBy === 'period'
            ? static fn (array $left, array $right): int => (int) $left['key'] <=> (int) $right['key']
            : static fn (array $left, array $right): int => strcmp((string) $left['code'].$left['label'], (string) $right['code'].$right['label']));

        $totalVariance = bcsub($totals['actual'], $totals['budget'], 2);

        return [
            'version_id' => $version->id,
            'through_period' => $throughPeriod,
            'group_by' => $groupBy,
            'rows' => $rows,
            'totals' => [
                'budget' => $totals['budget'],
                'actual' => $totals['actual'],
                'variance' => $totalVariance,
                'variance_percent' => bccomp($totals['budget'], '0', 2) === 0 ? null : bcround(bcmul(bcdiv($totalVariance, $totals['budget'], 8), '100', 8), 1),
                'adverse' => $adverse,
                'favorable' => $favorable,
            ],
        ];
    }

    /**
     * @return array{account_id: string, cost_center_id: string|null, project_id: string|null, period: int, budget: string, actual: string}
     */
    private function blank(string $accountId, ?string $costCenterId, ?string $projectId, int $period): array
    {
        return ['account_id' => $accountId, 'cost_center_id' => $costCenterId, 'project_id' => $projectId, 'period' => $period, 'budget' => '0.00', 'actual' => '0.00'];
    }

    private function groupKey(string $groupBy, string $accountId, ?string $costCenterId, ?string $projectId, int $period): string
    {
        return match ($groupBy) {
            'cost_center' => $costCenterId ?? '',
            'project' => $projectId ?? '',
            'period' => (string) $period,
            default => $accountId,
        };
    }

    /**
     * @param  array{account_id: string, cost_center_id: string|null, project_id: string|null, period: int, budget: string, actual: string}  $group
     */
    private function displayKey(string $groupBy, array $group): string
    {
        return match ($groupBy) {
            'cost_center' => $group['cost_center_id'] ?? '',
            'project' => $group['project_id'] ?? '',
            'period' => (string) $group['period'],
            default => $group['account_id'],
        };
    }

    /**
     * @param  array<string, array{account_id: string, cost_center_id: string|null, project_id: string|null, period: int, budget: string, actual: string}>  $groups
     * @param  array<string, array{code: string, name: string, kind: string}>  $accounts
     * @return array<string, array{label: string, code: string|null}>
     */
    private function labels(string $tenantId, string $groupBy, array $groups, array $accounts): array
    {
        $labels = [];

        if ($groupBy === 'account') {
            foreach ($groups as $key => $group) {
                $labels[$key] = ['label' => $accounts[$group['account_id']]['name'] ?? '', 'code' => $accounts[$group['account_id']]['code'] ?? null];
            }
        } elseif ($groupBy === 'cost_center') {
            $found = $this->ledger->costCenters($tenantId, array_values(array_filter(array_column($groups, 'cost_center_id'))));
            foreach ($groups as $key => $group) {
                $labels[$key] = ['label' => $found[$group['cost_center_id'] ?? '']['name'] ?? '', 'code' => $found[$group['cost_center_id'] ?? '']['code'] ?? null];
            }
        } elseif ($groupBy === 'project') {
            $found = $this->ledger->projects($tenantId, array_values(array_filter(array_column($groups, 'project_id'))));
            foreach ($groups as $key => $group) {
                $labels[$key] = ['label' => $found[$group['project_id'] ?? '']['name'] ?? '', 'code' => $found[$group['project_id'] ?? '']['code'] ?? null];
            }
        } else {
            foreach ($groups as $key => $group) {
                $labels[$key] = ['label' => (string) $group['period'], 'code' => null];
            }
        }

        return $labels;
    }
}
