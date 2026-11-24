<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use Modules\Budgeting\Contracts\LedgerLookup;
use Modules\Budgeting\Models\BudgetLine;
use Modules\Budgeting\Models\BudgetVersion;

/**
 * Side by side comparison of the budget of two versions (scenarios, for example a budget against a forecast): the amounts
 * are summed per account, cost centre, project or period up to a fiscal period, the delta is scenario minus base.
 */
final readonly class ScenarioCompareService
{
    public function __construct(private LedgerLookup $ledger, private VarianceService $variance) {}

    /**
     * @return array{base_version_id: string, scenario_version_id: string, through_period: int, group_by: string, rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function compare(BudgetVersion $base, BudgetVersion $scenario, int $throughPeriod, string $groupBy): array
    {
        $throughPeriod = max(1, min(BudgetPeriods::Count, $throughPeriod));
        $groups = [];

        foreach ([[$base, 'budget'], [$scenario, 'actual']] as [$version, $side]) {
            foreach (BudgetLine::query()->where('version_id', $version->id)->where('period', '<=', $throughPeriod)->get() as $line) {
                $key = match ($groupBy) {
                    'cost_center' => $line->cost_center_id ?? '',
                    'project' => $line->project_id ?? '',
                    'period' => (string) $line->period,
                    default => (string) $line->account_id,
                };
                $groups[$key] ??= ['account_id' => (string) $line->account_id, 'cost_center_id' => $line->cost_center_id, 'project_id' => $line->project_id, 'period' => (int) $line->period, 'budget' => '0.00', 'actual' => '0.00'];
                $groups[$key][$side] = bcadd($groups[$key][$side], (string) $line->amount, 2);
            }
        }

        $accountIds = array_values(array_unique(array_column($groups, 'account_id')));
        $labels = $this->variance->labels((string) $base->tenant_id, $groupBy, $groups, $this->ledger->accounts((string) $base->tenant_id, $accountIds));
        $rows = [];
        $totals = ['base' => '0.00', 'scenario' => '0.00'];

        foreach ($groups as $key => $group) {
            $delta = bcsub($group['actual'], $group['budget'], 2);
            $totals['base'] = bcadd($totals['base'], $group['budget'], 2);
            $totals['scenario'] = bcadd($totals['scenario'], $group['actual'], 2);
            $rows[] = [
                'key' => match ($groupBy) {
                    'cost_center' => $group['cost_center_id'] ?? '',
                    'project' => $group['project_id'] ?? '',
                    'period' => (string) $group['period'],
                    default => $group['account_id'],
                },
                'label' => $labels[$key]['label'] ?? '',
                'code' => $labels[$key]['code'] ?? null,
                'base' => $group['budget'],
                'scenario' => $group['actual'],
                'delta' => $delta,
                'delta_percent' => bccomp($group['budget'], '0', 2) === 0 ? null : bcround(bcmul(bcdiv($delta, $group['budget'], 8), '100', 8), 1),
            ];
        }

        usort($rows, $groupBy === 'period'
            ? static fn (array $left, array $right): int => (int) $left['key'] <=> (int) $right['key']
            : static fn (array $left, array $right): int => strcmp((string) $left['code'].$left['label'], (string) $right['code'].$right['label']));

        $totalDelta = bcsub($totals['scenario'], $totals['base'], 2);

        return [
            'base_version_id' => (string) $base->id,
            'scenario_version_id' => (string) $scenario->id,
            'through_period' => $throughPeriod,
            'group_by' => $groupBy,
            'rows' => $rows,
            'totals' => [
                'base' => $totals['base'],
                'scenario' => $totals['scenario'],
                'delta' => $totalDelta,
                'delta_percent' => bccomp($totals['base'], '0', 2) === 0 ? null : bcround(bcmul(bcdiv($totalDelta, $totals['base'], 8), '100', 8), 1),
            ],
        ];
    }
}
