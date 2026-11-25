<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use Modules\Budgeting\Contracts\LedgerLookup;
use Modules\Budgeting\Models\BudgetActualVolume;
use Modules\Budgeting\Models\BudgetLine;
use Modules\Budgeting\Models\BudgetVersion;

/**
 * Splits the variance into a volume and a price effect, cell by cell (account, cost centre and project), and adds the
 * cells up to the group asked for.
 *
 *   Variance        = Actual amount - Budget amount
 *   Budget price    = Budget amount / Budget quantity
 *   Volume variance = (Actual quantity - Budget quantity) x Budget price
 *   Price variance  = (Actual price - Budget price) x Actual quantity  (= Actual amount - Actual quantity x Budget price)
 *   Volume variance + Price variance = Variance, exactly.
 *
 * The budget quantity comes from the budget lines (every line of the cell must carry one), the actual quantity from the
 * actual volumes recorded for the same account, cost centre and project, and the actual amount from the ledger. A cell
 * that lacks either quantity keeps its monetary variance, reported apart as undecomposed. A positive variance on an
 * expense is adverse.
 */
final readonly class VolumePriceVarianceService
{
    public const array GroupBy = ['account', 'cost_center', 'project'];

    public function __construct(private LedgerLookup $ledger) {}

    /**
     * @param  array{account_id?: string|null, cost_center_id?: string|null, project_id?: string|null}  $filters
     * @return array{version_id: string, through_period: int, group_by: string, rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function decompose(BudgetVersion $version, int $throughPeriod, string $groupBy, array $filters = []): array
    {
        $throughPeriod = max(1, min(BudgetPeriods::Count, $throughPeriod));
        $cells = $this->cells($version, $throughPeriod, $filters);

        return $this->report($version, $throughPeriod, $groupBy, $cells);
    }

    /**
     * The decomposition of one cell; null effects when the quantities are missing.
     *
     * @return array{volume: string|null, price: string|null, budget_price: string|null, actual_price: string|null}
     */
    public static function effects(string $budget, string $actual, ?string $budgetQuantity, ?string $actualQuantity): array
    {
        if ($budgetQuantity === null || $actualQuantity === null || bccomp($budgetQuantity, '0', 3) <= 0) {
            return ['volume' => null, 'price' => null, 'budget_price' => null, 'actual_price' => null];
        }

        $budgetPrice = bcdiv($budget, $budgetQuantity, 8);
        $volume = bcround(bcmul(bcsub($actualQuantity, $budgetQuantity, 3), $budgetPrice, 8), 2);
        $price = bcsub(bcsub($actual, $budget, 2), $volume, 2);

        return [
            'volume' => $volume,
            'price' => $price,
            'budget_price' => bcround($budgetPrice, 4),
            'actual_price' => bccomp($actualQuantity, '0', 3) > 0 ? bcround(bcdiv($actual, $actualQuantity, 8), 4) : null,
        ];
    }

    /**
     * Budget, actual and quantities per cell up to the period.
     *
     * @param  array{account_id?: string|null, cost_center_id?: string|null, project_id?: string|null}  $filters
     * @return array<string, array{account_id: string, cost_center_id: string|null, project_id: string|null, budget: string, actual: string, budget_quantity: string|null, quantity_missing: bool, actual_quantity: string|null}>
     */
    private function cells(BudgetVersion $version, int $throughPeriod, array $filters): array
    {
        $cells = [];
        $lines = BudgetLine::query()->where('version_id', $version->id)->where('period', '<=', $throughPeriod)
            ->when(! empty($filters['account_id']), static fn ($query) => $query->where('account_id', $filters['account_id']))
            ->when(! empty($filters['cost_center_id']), static fn ($query) => $query->where('cost_center_id', $filters['cost_center_id']))
            ->when(! empty($filters['project_id']), static fn ($query) => $query->where('project_id', $filters['project_id']))
            ->get();

        foreach ($lines as $line) {
            $cell = &$this->cell($cells, $line->account_id, $line->cost_center_id, $line->project_id);
            $cell['budget'] = bcadd($cell['budget'], (string) $line->amount, 2);

            if ($line->quantity === null) {
                $cell['quantity_missing'] = true;
            } else {
                $cell['budget_quantity'] = bcadd($cell['budget_quantity'] ?? '0', (string) $line->quantity, 3);
            }

            unset($cell);
        }

        $budgetedAccounts = array_values(array_unique($lines->pluck('account_id')->all()));
        $start = BudgetPeriods::periodStart($version, 1);
        $end = BudgetPeriods::periodEnd($version, $throughPeriod);

        foreach ($this->ledger->monthlyActuals($version->tenant_id, $start, $end) as $row) {
            if (! in_array($row['account_id'], $budgetedAccounts, true) || ! $this->matches($filters, $row['account_id'], $row['cost_center_id'], $row['project_id'])) {
                continue;
            }

            $cell = &$this->cell($cells, $row['account_id'], $row['cost_center_id'], $row['project_id']);
            $cell['actual'] = bcadd($cell['actual'], $row['amount'], 2);
            unset($cell);
        }

        $volumes = BudgetActualVolume::query()
            ->where('tenant_id', $version->tenant_id)
            ->where('month', '>=', $start->format('Y-m'))
            ->where('month', '<=', $end->format('Y-m'))
            ->whereIn('account_id', $budgetedAccounts)
            ->get();

        foreach ($volumes as $volume) {
            if (! $this->matches($filters, $volume->account_id, $volume->cost_center_id, $volume->project_id)) {
                continue;
            }

            $cell = &$this->cell($cells, $volume->account_id, $volume->cost_center_id, $volume->project_id);
            $cell['actual_quantity'] = bcadd($cell['actual_quantity'] ?? '0', (string) $volume->quantity, 3);
            unset($cell);
        }

        return $cells;
    }

    /**
     * @param  array<string, array{account_id: string, cost_center_id: string|null, project_id: string|null, budget: string, actual: string, budget_quantity: string|null, quantity_missing: bool, actual_quantity: string|null}>  $cells
     * @return array{version_id: string, through_period: int, group_by: string, rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    private function report(BudgetVersion $version, int $throughPeriod, string $groupBy, array $cells): array
    {
        $groups = [];

        foreach ($cells as $cell) {
            $effects = self::effects($cell['budget'], $cell['actual'], $cell['quantity_missing'] ? null : $cell['budget_quantity'], $cell['actual_quantity']);
            $key = match ($groupBy) {
                'cost_center' => $cell['cost_center_id'] ?? '',
                'project' => $cell['project_id'] ?? '',
                default => $cell['account_id'],
            };

            $group = $groups[$key] ?? ['account_id' => $cell['account_id'], 'cost_center_id' => $cell['cost_center_id'], 'project_id' => $cell['project_id'], 'budget' => '0.00', 'actual' => '0.00', 'volume' => null, 'price' => null, 'undecomposed' => '0.00', 'decomposed_budget' => '0.00', 'decomposed_actual' => '0.00', 'budget_quantity' => null, 'actual_quantity' => null];
            $group['budget'] = bcadd($group['budget'], $cell['budget'], 2);
            $group['actual'] = bcadd($group['actual'], $cell['actual'], 2);

            if ($effects['volume'] === null) {
                $group['undecomposed'] = bcadd($group['undecomposed'], bcsub($cell['actual'], $cell['budget'], 2), 2);
            } else {
                $group['decomposed_budget'] = bcadd($group['decomposed_budget'], $cell['budget'], 2);
                $group['decomposed_actual'] = bcadd($group['decomposed_actual'], $cell['actual'], 2);
                $group['volume'] = bcadd($group['volume'] ?? '0', $effects['volume'], 2);
                $group['price'] = bcadd($group['price'] ?? '0', (string) $effects['price'], 2);
                $group['budget_quantity'] = bcadd($group['budget_quantity'] ?? '0', (string) $cell['budget_quantity'], 3);
                $group['actual_quantity'] = bcadd($group['actual_quantity'] ?? '0', (string) $cell['actual_quantity'], 3);
            }

            $groups[$key] = $group;
        }

        $accounts = $this->ledger->accounts($version->tenant_id, array_values(array_unique(array_column($groups, 'account_id'))));
        $costCenters = $this->ledger->costCenters($version->tenant_id, array_values(array_filter(array_unique(array_column($groups, 'cost_center_id')))));
        $projects = $this->ledger->projects($version->tenant_id, array_values(array_filter(array_unique(array_column($groups, 'project_id')))));
        $rows = [];
        $totals = ['budget' => '0.00', 'actual' => '0.00', 'variance' => '0.00', 'volume_variance' => '0.00', 'price_variance' => '0.00', 'undecomposed_variance' => '0.00'];

        foreach ($groups as $key => $group) {
            $variance = bcsub($group['actual'], $group['budget'], 2);
            $info = match ($groupBy) {
                'cost_center' => $costCenters[$group['cost_center_id']] ?? ['code' => '', 'name' => ''],
                'project' => $projects[$group['project_id']] ?? ['code' => '', 'name' => ''],
                default => $accounts[$group['account_id']] ?? ['code' => '', 'name' => ''],
            };
            $isRevenue = $groupBy === 'account' && ($accounts[$group['account_id']]['kind'] ?? 'other') === 'revenue';
            $effects = $groupBy === 'account' && $group['volume'] !== null
                ? self::effects($group['decomposed_budget'], $group['decomposed_actual'], $group['budget_quantity'], $group['actual_quantity'])
                : ['budget_price' => null, 'actual_price' => null];

            $totals['budget'] = bcadd($totals['budget'], $group['budget'], 2);
            $totals['actual'] = bcadd($totals['actual'], $group['actual'], 2);
            $totals['variance'] = bcadd($totals['variance'], $variance, 2);
            $totals['volume_variance'] = bcadd($totals['volume_variance'], $group['volume'] ?? '0', 2);
            $totals['price_variance'] = bcadd($totals['price_variance'], $group['price'] ?? '0', 2);
            $totals['undecomposed_variance'] = bcadd($totals['undecomposed_variance'], $group['undecomposed'], 2);

            $rows[] = [
                'key' => (string) $key,
                'code' => $info['code'],
                'label' => $info['name'],
                'budget' => $group['budget'],
                'actual' => $group['actual'],
                'variance' => $variance,
                'is_favorable' => $isRevenue ? bccomp($variance, '0', 2) >= 0 : bccomp($variance, '0', 2) <= 0,
                'budget_quantity' => $groupBy === 'account' ? $group['budget_quantity'] : null,
                'actual_quantity' => $groupBy === 'account' ? $group['actual_quantity'] : null,
                'budget_price' => $effects['budget_price'],
                'actual_price' => $effects['actual_price'],
                'volume_variance' => $group['volume'],
                'price_variance' => $group['price'],
                'undecomposed_variance' => $group['undecomposed'],
                'decomposable' => $group['volume'] !== null,
            ];
        }

        usort($rows, static fn (array $left, array $right): int => strcmp((string) $left['code'].$left['label'], (string) $right['code'].$right['label']));

        return ['version_id' => $version->id, 'through_period' => $throughPeriod, 'group_by' => $groupBy, 'rows' => $rows, 'totals' => $totals];
    }

    /**
     * @param  array<string, array{account_id: string, cost_center_id: string|null, project_id: string|null, budget: string, actual: string, budget_quantity: string|null, quantity_missing: bool, actual_quantity: string|null}>  $cells
     * @return array{account_id: string, cost_center_id: string|null, project_id: string|null, budget: string, actual: string, budget_quantity: string|null, quantity_missing: bool, actual_quantity: string|null}
     */
    private function &cell(array &$cells, string $accountId, ?string $costCenterId, ?string $projectId): array
    {
        $key = $accountId."\0".($costCenterId ?? '')."\0".($projectId ?? '');
        $cells[$key] ??= ['account_id' => $accountId, 'cost_center_id' => $costCenterId, 'project_id' => $projectId, 'budget' => '0.00', 'actual' => '0.00', 'budget_quantity' => null, 'quantity_missing' => false, 'actual_quantity' => null];

        return $cells[$key];
    }

    /**
     * @param  array{account_id?: string|null, cost_center_id?: string|null, project_id?: string|null}  $filters
     */
    private function matches(array $filters, string $accountId, ?string $costCenterId, ?string $projectId): bool
    {
        return (empty($filters['account_id']) || $filters['account_id'] === $accountId)
            && (empty($filters['cost_center_id']) || $filters['cost_center_id'] === $costCenterId)
            && (empty($filters['project_id']) || $filters['project_id'] === $projectId);
    }
}
