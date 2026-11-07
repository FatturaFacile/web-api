<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\CostCenter;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\LedgerBudget;
use Modules\GeneralLedger\Models\LedgerBudgetLine;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Annual budget by account and cost centre, in versions, compared with the ledger. Amounts are entered in the natural
 * sign of the account, as the ledger reads them: revenue and costs are both positive.
 */
final readonly class LedgerBudgetService
{
    private const int Scale = 4;

    public function __construct(private LedgerBalanceReader $balances) {}

    /**
     * @param  array{fiscal_year_id: string, name: string, notes?: ?string}  $data
     */
    public function create(string $tenantId, array $data, string $userId): LedgerBudget
    {
        return LedgerBudget::create([...$data, 'tenant_id' => $tenantId, 'version' => 1, 'status' => 'draft', 'created_by' => $userId]);
    }

    /**
     * Sets amounts on a draft: a line with the same account, cost centre and period is replaced.
     *
     * @param  list<array{account_id: string, cost_center_id?: ?string, period_number: int, amount: numeric-string|float|int}>  $lines
     *
     * @throws ConflictHttpException when the version is no longer a draft
     */
    public function setLines(LedgerBudget $budget, array $lines): LedgerBudget
    {
        return DB::transaction(function () use ($budget, $lines): LedgerBudget {
            $locked = LedgerBudget::query()->lockForUpdate()->findOrFail($budget->id);

            if ($locked->status !== 'draft') {
                throw new ConflictHttpException('Only a draft can change: create a new version.');
            }

            foreach ($lines as $line) {
                LedgerBudgetLine::updateOrCreate(
                    [
                        'tenant_id' => $locked->tenant_id,
                        'budget_id' => $locked->id,
                        'account_id' => $line['account_id'],
                        'cost_center_id' => $line['cost_center_id'] ?? null,
                        'period_number' => $line['period_number'],
                    ],
                    ['amount' => (string) $line['amount']]
                );
            }

            return $locked;
        });
    }

    /**
     * Copies a version into the next draft. Earlier versions stay as they are, so a variance can always be reproduced.
     */
    public function newVersion(LedgerBudget $budget, string $userId): LedgerBudget
    {
        return DB::transaction(function () use ($budget, $userId): LedgerBudget {
            $latest = (int) LedgerBudget::query()
                ->where('fiscal_year_id', $budget->fiscal_year_id)
                ->where('name', $budget->name)
                ->lockForUpdate()
                ->max('version');

            $copy = LedgerBudget::create([
                'tenant_id' => $budget->tenant_id,
                'company_id' => $budget->company_id,
                'fiscal_year_id' => $budget->fiscal_year_id,
                'name' => $budget->name,
                'version' => $latest + 1,
                'status' => 'draft',
                'notes' => $budget->notes,
                'created_by' => $userId,
            ]);

            foreach ($budget->lines as $line) {
                LedgerBudgetLine::create([
                    'tenant_id' => $copy->tenant_id,
                    'budget_id' => $copy->id,
                    'account_id' => $line->account_id,
                    'cost_center_id' => $line->cost_center_id,
                    'period_number' => $line->period_number,
                    'amount' => $line->amount,
                ]);
            }

            return $copy;
        });
    }

    /**
     * Approves a draft. The version approved before it is superseded.
     *
     * @throws ConflictHttpException when the version is not a draft
     */
    public function approve(LedgerBudget $budget, string $userId): LedgerBudget
    {
        return DB::transaction(function () use ($budget, $userId): LedgerBudget {
            $locked = LedgerBudget::query()->lockForUpdate()->findOrFail($budget->id);

            if ($locked->status !== 'draft') {
                throw new ConflictHttpException('Only a draft can be approved.');
            }

            LedgerBudget::query()
                ->where('fiscal_year_id', $locked->fiscal_year_id)
                ->where('name', $locked->name)
                ->where('status', 'approved')
                ->update(['status' => 'superseded']);

            $locked->update(['status' => 'approved', 'approved_by' => $userId, 'approved_at' => now()]);

            return $locked;
        });
    }

    /**
     * Actual versus budget per account and cost centre. Actual amounts with no budget line appear as well: an
     * unbudgeted cost is exactly what a variance report must show. Balance sheet accounts enter only if budgeted.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array{budget: string, actual: string, variance: string}}
     */
    public function variance(LedgerBudget $budget, int $periodFrom, int $periodTo, ?string $costCenterId): array
    {
        $grid = $this->grid($budget);
        $budgeted = $grid['budget'];
        $actual = $grid['actual'];
        $rows = [];
        $totals = ['budget' => '0', 'actual' => '0', 'variance' => '0'];

        foreach (array_unique([...array_keys($budgeted), ...array_keys($actual)]) as $key) {
            [$accountId, $center] = explode('|', $key);
            $center = $center === '' ? null : $center;

            if (($costCenterId !== null && $center !== $costCenterId) || ! $this->isReportable($accountId, $budgeted, $key)) {
                continue;
            }

            $budgetAmount = $this->sumRange($budgeted[$key] ?? [], $periodFrom, $periodTo);
            $actualAmount = $this->sumRange($actual[$key] ?? [], $periodFrom, $periodTo);
            $variance = bcsub($actualAmount, $budgetAmount, self::Scale);

            $rows[] = [
                'account_id' => $accountId,
                'cost_center_id' => $center,
                'budget' => $budgetAmount,
                'actual' => $actualAmount,
                'variance' => $variance,
                'variance_percent' => bccomp($budgetAmount, '0', self::Scale) === 0 ? null : bcmul(bcdiv($variance, $budgetAmount, self::Scale + 2), '100', 2),
            ];

            $totals = [
                'budget' => bcadd($totals['budget'], $budgetAmount, self::Scale),
                'actual' => bcadd($totals['actual'], $actualAmount, self::Scale),
                'variance' => bcadd($totals['variance'], $variance, self::Scale),
            ];
        }

        return ['rows' => $this->named($rows), 'totals' => $totals];
    }

    /**
     * Full-year outlook: actual amounts up to `$asOfPeriod`, then either the budget of the remaining months or the
     * average of the months already closed.
     *
     * @param  string  $method  budget or run_rate
     * @return array{as_of_period: int, method: string, rows: list<array<string, mixed>>, totals: array{actual_to_date: string, forecast_remaining: string, full_year_forecast: string, full_year_budget: string}}
     */
    public function rollingForecast(LedgerBudget $budget, int $asOfPeriod, string $method): array
    {
        $grid = $this->grid($budget);
        $periods = FiscalPeriod::query()->where('fiscal_year_id', $budget->fiscal_year_id)->where('period_number', '<=', 12)->orderBy('period_number')->pluck('period_number')->all();
        $rows = [];
        $totals = ['actual_to_date' => '0', 'forecast_remaining' => '0', 'full_year_forecast' => '0', 'full_year_budget' => '0'];

        foreach (array_unique([...array_keys($grid['budget']), ...array_keys($grid['actual'])]) as $key) {
            [$accountId, $center] = explode('|', $key);

            if (! $this->isReportable($accountId, $grid['budget'], $key)) {
                continue;
            }

            $actualToDate = $this->sumRange($grid['actual'][$key] ?? [], 1, $asOfPeriod);
            $runRate = $asOfPeriod > 0 ? bcdiv($actualToDate, (string) $asOfPeriod, self::Scale) : '0';
            $months = [];
            $remaining = '0';

            foreach ($periods as $period) {
                if ($period <= $asOfPeriod) {
                    $months[$period] = ['basis' => 'actual', 'amount' => $grid['actual'][$key][$period] ?? '0.0000'];

                    continue;
                }

                $amount = $method === 'run_rate' ? $runRate : ($grid['budget'][$key][$period] ?? '0.0000');
                $months[$period] = ['basis' => $method, 'amount' => $amount];
                $remaining = bcadd($remaining, $amount, self::Scale);
            }

            $fullYear = bcadd($actualToDate, $remaining, self::Scale);
            $fullBudget = $this->sumRange($grid['budget'][$key] ?? [], 1, 12);

            $rows[] = [
                'account_id' => $accountId,
                'cost_center_id' => $center === '' ? null : $center,
                'months' => $months,
                'actual_to_date' => $actualToDate,
                'forecast_remaining' => $remaining,
                'full_year_forecast' => $fullYear,
                'full_year_budget' => $fullBudget,
            ];

            $totals = [
                'actual_to_date' => bcadd($totals['actual_to_date'], $actualToDate, self::Scale),
                'forecast_remaining' => bcadd($totals['forecast_remaining'], $remaining, self::Scale),
                'full_year_forecast' => bcadd($totals['full_year_forecast'], $fullYear, self::Scale),
                'full_year_budget' => bcadd($totals['full_year_budget'], $fullBudget, self::Scale),
            ];
        }

        return ['as_of_period' => $asOfPeriod, 'method' => $method, 'rows' => $this->named($rows), 'totals' => $totals];
    }

    /**
     * Budget and actual amounts keyed by `account|cost centre`, then by period number.
     *
     * @return array{budget: array<string, array<int, string>>, actual: array<string, array<int, string>>}
     */
    private function grid(LedgerBudget $budget): array
    {
        $grid = ['budget' => [], 'actual' => []];

        foreach ($budget->lines as $line) {
            $grid['budget']["{$line->account_id}|{$line->cost_center_id}"][$line->period_number] = (string) $line->amount;
        }

        $periodIds = FiscalPeriod::query()->where('fiscal_year_id', $budget->fiscal_year_id)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();

        foreach ($this->balances->actualsByPeriod((string) $budget->tenant_id, $periodIds) as $row) {
            $grid['actual']["{$row->account_id}|{$row->cost_center_id}"][(int) $row->period_number] = $this->balances->naturalAmount($row);
        }

        return $grid;
    }

    /**
     * Income accounts always count. A balance sheet account counts only where someone budgeted it.
     *
     * @param  array<string, array<int, string>>  $budgeted
     */
    private function isReportable(string $accountId, array $budgeted, string $key): bool
    {
        return isset($budgeted[$key]) || in_array(ChartOfAccount::query()->whereKey($accountId)->value('account_type'), LedgerBalanceReader::IncomeTypes, true);
    }

    /**
     * @param  array<int, string>  $amounts
     */
    private function sumRange(array $amounts, int $from, int $to): string
    {
        $sum = '0';

        foreach ($amounts as $period => $amount) {
            if ($period >= $from && $period <= $to) {
                $sum = bcadd($sum, $amount, self::Scale);
            }
        }

        return $sum === '0' ? '0.0000' : $sum;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function named(array $rows): array
    {
        $rowCollection = new Collection($rows);
        $accounts = ChartOfAccount::query()->whereIn('id', $rowCollection->pluck('account_id'))->get(['id', 'account_code', 'name'])->keyBy('id');
        $centers = CostCenter::query()->whereIn('id', $rowCollection->pluck('cost_center_id')->filter())->get(['id', 'code'])->keyBy('id');

        return $rowCollection
            ->map(static fn (array $row): array => [
                ...$row,
                'account_code' => $accounts->get($row['account_id'])?->account_code,
                'account_name' => $accounts->get($row['account_id'])?->name,
                'cost_center_code' => $row['cost_center_id'] === null ? null : $centers->get($row['cost_center_id'])?->code,
            ])
            ->sortBy('account_code')
            ->values()
            ->all();
    }
}
