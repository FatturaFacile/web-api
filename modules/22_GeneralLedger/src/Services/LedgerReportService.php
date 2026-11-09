<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Models\ChartOfAccount;

/**
 * Reports read from the posted entries of the statutory ledger only: trial balance, account ledger (mastrino) with
 * running balance, balance sheet, income statement and analysis by dimension, each with an optional comparison range.
 *
 * Conventions shared with LedgerBalanceReader: an entry counts when it is posted or reversed (a reversed entry and its
 * reversal both stay, so they cancel out); opening entries (journal_type `opening`) repeat balances already in the
 * ledger and are left out; closing entries of the year-end are left out of the income statement and of the movements,
 * and kept in the cumulative balances, where they move past results into equity. Amounts are bcmath strings at 4
 * decimals; a balance is debit minus credit unless stated as natural (positive on the normal side of the account).
 */
final class LedgerReportService
{
    private const int Scale = 4;

    /**
     * @param  array{cost_center_id?: ?string, profit_center_id?: ?string, project_id?: ?string}  $dimensions
     * @return array{from: string, to: string, compare_from: ?string, compare_to: ?string, accounts: list<array<string, mixed>>, totals: array<string, string>, is_balanced: bool}
     */
    public function trialBalance(string $tenantId, CarbonImmutable $from, CarbonImmutable $to, ?CarbonImmutable $compareFrom = null, ?CarbonImmutable $compareTo = null, array $dimensions = []): array
    {
        $opening = $this->sums($this->lines($tenantId, $dimensions)->where('journal_entries.posting_date', '<', $from->toDateString()));
        $period = $this->sums($this->lines($tenantId, $dimensions)->whereBetween('journal_entries.posting_date', [$from->toDateString(), $to->toDateString()]));
        $comparison = $compareFrom !== null && $compareTo !== null
            ? $this->sums($this->lines($tenantId, $dimensions)->whereBetween('journal_entries.posting_date', [$compareFrom->toDateString(), $compareTo->toDateString()]))
            : collect();

        $accountIds = $opening->keys()->merge($period->keys())->merge($comparison->keys())->unique()->values();
        $accounts = $this->accounts($tenantId, $accountIds->all());
        $totals = ['opening_balance' => '0', 'debit' => '0', 'credit' => '0', 'closing_balance' => '0'];
        $rows = [];

        foreach ($accounts as $account) {
            $id = (string) $account->id;
            $openingBalance = $this->balanceOf($opening->get($id));
            $debit = (string) ($period->get($id)->debit ?? '0');
            $credit = (string) ($period->get($id)->credit ?? '0');
            $closing = bcadd($openingBalance, bcsub($debit, $credit, self::Scale), self::Scale);

            $row = [
                'account_id' => $id,
                'account_code' => $account->account_code,
                'account_name' => $account->name,
                'account_type' => $account->account_type,
                'normal_balance' => $account->normal_balance,
                'opening_balance' => $openingBalance,
                'debit' => $this->scaled($debit),
                'credit' => $this->scaled($credit),
                'closing_balance' => $closing,
                'drill_down' => $this->drillDown($id, $from, $to),
            ];

            if ($compareFrom !== null && $compareTo !== null) {
                $row['comparison_debit'] = $this->scaled((string) ($comparison->get($id)->debit ?? '0'));
                $row['comparison_credit'] = $this->scaled((string) ($comparison->get($id)->credit ?? '0'));
            }

            $totals['opening_balance'] = bcadd($totals['opening_balance'], $openingBalance, self::Scale);
            $totals['debit'] = bcadd($totals['debit'], $debit, self::Scale);
            $totals['credit'] = bcadd($totals['credit'], $credit, self::Scale);
            $totals['closing_balance'] = bcadd($totals['closing_balance'], $closing, self::Scale);
            $rows[] = $row;
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'compare_from' => $compareFrom?->toDateString(),
            'compare_to' => $compareTo?->toDateString(),
            'accounts' => $rows,
            'totals' => array_map(fn (string $amount): string => $this->scaled($amount), $totals),
            'is_balanced' => bccomp($totals['debit'], $totals['credit'], self::Scale) === 0 && bccomp($totals['closing_balance'], '0', self::Scale) === 0,
        ];
    }

    /**
     * The lines of one account in a range, oldest first, with the balance before the range and the running balance
     * after each line. The running balance of a page already includes every earlier line of the range.
     *
     * @param  array{cost_center_id?: ?string, profit_center_id?: ?string, project_id?: ?string}  $dimensions
     * @return array{account: array<string, mixed>, from: string, to: string, opening_balance: string, closing_balance: string, total_debit: string, total_credit: string, lines: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function accountLedger(string $tenantId, ChartOfAccount $account, CarbonImmutable $from, CarbonImmutable $to, int $page, int $perPage, array $dimensions = []): array
    {
        $opening = $this->balanceOf($this->sums($this->lines($tenantId, $dimensions)
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_entries.posting_date', '<', $from->toDateString()))->get((string) $account->id));

        $inRange = fn (): Builder => $this->lines($tenantId, $dimensions)
            ->where('journal_lines.account_id', $account->id)
            ->whereBetween('journal_entries.posting_date', [$from->toDateString(), $to->toDateString()]);

        $totals = $inRange()->selectRaw('COUNT(*) as line_count, COALESCE(SUM(journal_lines.debit_amount), 0) as debit, COALESCE(SUM(journal_lines.credit_amount), 0) as credit')->first();
        $total = (int) ($totals->line_count ?? 0);
        $order = 'journal_entries.posting_date, journal_entries.journal_number, journal_entries.created_at, journal_lines.line_number, journal_lines.id';

        $rows = $inRange()
            ->selectRaw("journal_lines.id as line_id, journal_entries.id as journal_entry_id, journal_entries.entry_number, journal_entries.journal_number, journal_entries.posting_date, journal_entries.journal_type, journal_entries.status, journal_entries.source_document_type, journal_entries.source_document_id, journal_lines.description, journal_lines.partner_type, journal_lines.partner_id, journal_lines.cost_center_id, journal_lines.profit_center_id, journal_lines.project_id, journal_lines.currency, journal_lines.debit_amount, journal_lines.credit_amount, SUM(journal_lines.debit_amount - journal_lines.credit_amount) OVER (ORDER BY {$order} ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) as cumulative")
            ->orderByRaw($order)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $lines = $rows->map(fn (object $row): array => [
            'line_id' => (string) $row->line_id,
            'journal_entry_id' => (string) $row->journal_entry_id,
            'entry_number' => $row->entry_number,
            'journal_number' => $row->journal_number !== null ? (int) $row->journal_number : null,
            'posting_date' => CarbonImmutable::parse($row->posting_date)->toDateString(),
            'journal_type' => $row->journal_type,
            'status' => $row->status,
            'source_document_type' => $row->source_document_type,
            'source_document_id' => $row->source_document_id,
            'description' => $row->description,
            'partner_type' => $row->partner_type,
            'partner_id' => $row->partner_id,
            'cost_center_id' => $row->cost_center_id,
            'profit_center_id' => $row->profit_center_id,
            'project_id' => $row->project_id,
            'currency' => $row->currency,
            'debit' => $this->scaled((string) $row->debit_amount),
            'credit' => $this->scaled((string) $row->credit_amount),
            'running_balance' => bcadd($opening, (string) $row->cumulative, self::Scale),
        ])->values()->all();

        $debit = (string) ($totals->debit ?? '0');
        $credit = (string) ($totals->credit ?? '0');

        return [
            'account' => ['id' => (string) $account->id, 'account_code' => $account->account_code, 'name' => $account->name, 'account_type' => $account->account_type, 'normal_balance' => $account->normal_balance],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'opening_balance' => $opening,
            'closing_balance' => bcadd($opening, bcsub($debit, $credit, self::Scale), self::Scale),
            'total_debit' => $this->scaled($debit),
            'total_credit' => $this->scaled($credit),
            'lines' => $lines,
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'per_page' => $perPage, 'total' => $total],
        ];
    }

    /**
     * Balance sheet at a date in the natural sign of each section, with the result not yet closed into equity shown
     * on its own line; optionally next to the same at a comparison date.
     *
     * @return array<string, mixed>
     */
    public function balanceSheet(string $tenantId, CarbonImmutable $asOf, ?CarbonImmutable $compareAsOf = null): array
    {
        $current = $this->balanceSheetAt($tenantId, $asOf);
        $comparison = $compareAsOf !== null ? $this->balanceSheetAt($tenantId, $compareAsOf) : null;

        return [
            'as_of' => $asOf->toDateString(),
            'compare_as_of' => $compareAsOf?->toDateString(),
            'sections' => $this->mergeSections($current['sections'], $comparison['sections'] ?? null),
            'totals' => $current['totals'],
            'comparison_totals' => $comparison['totals'] ?? null,
            'is_balanced' => $current['is_balanced'],
        ];
    }

    /**
     * Income statement of a range in the natural sign (revenue positive, costs positive), without the year-end
     * closing entries; optionally next to a comparison range, with the variance.
     *
     * @param  array{cost_center_id?: ?string, profit_center_id?: ?string, project_id?: ?string}  $dimensions
     * @return array<string, mixed>
     */
    public function incomeStatement(string $tenantId, CarbonImmutable $from, CarbonImmutable $to, ?CarbonImmutable $compareFrom = null, ?CarbonImmutable $compareTo = null, array $dimensions = []): array
    {
        $current = $this->incomeStatementOf($tenantId, $from, $to, $dimensions);
        $comparison = $compareFrom !== null && $compareTo !== null ? $this->incomeStatementOf($tenantId, $compareFrom, $compareTo, $dimensions) : null;

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'compare_from' => $compareFrom?->toDateString(),
            'compare_to' => $compareTo?->toDateString(),
            'sections' => $this->mergeSections($current['sections'], $comparison['sections'] ?? null),
            'totals' => $current['totals'],
            'comparison_totals' => $comparison['totals'] ?? null,
        ];
    }

    /**
     * Net natural amounts per value of a dimension (cost centre, profit centre or project) and account, for the
     * income statement accounts of a range. Each group gives its result (revenue minus costs). Lines without the
     * dimension are grouped under a null value, so the sum of the groups equals the net income of the range.
     *
     * @return array{dimension: string, from: string, to: string, groups: list<array<string, mixed>>, total: string}
     */
    public function dimensionAnalysis(string $tenantId, string $dimension, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $column = match ($dimension) {
            'cost_center' => 'cost_center_id',
            'profit_center' => 'profit_center_id',
            default => 'project_id',
        };

        $rows = $this->lines($tenantId)
            ->whereBetween('journal_entries.posting_date', [$from->toDateString(), $to->toDateString()])
            ->where('journal_entries.journal_type', '!=', 'closing')
            ->whereIn('chart_of_accounts.account_type', LedgerBalanceReader::IncomeTypes)
            ->groupBy("journal_lines.{$column}", 'chart_of_accounts.id', 'chart_of_accounts.account_code', 'chart_of_accounts.name', 'chart_of_accounts.account_type', 'chart_of_accounts.normal_balance')
            ->orderBy('chart_of_accounts.account_code')
            ->selectRaw("journal_lines.{$column} as dimension_id, chart_of_accounts.id as account_id, chart_of_accounts.account_code, chart_of_accounts.name as account_name, chart_of_accounts.account_type, chart_of_accounts.normal_balance, SUM(journal_lines.debit_amount) as debit, SUM(journal_lines.credit_amount) as credit")
            ->get();

        $names = $this->dimensionNames($tenantId, $dimension, $rows->pluck('dimension_id')->filter()->unique()->values()->all());
        $groups = [];
        $total = '0';

        foreach ($rows->groupBy(static fn (object $row): string => (string) ($row->dimension_id ?? '')) as $dimensionId => $accountRows) {
            $groupTotal = '0';
            $accounts = [];

            foreach ($accountRows as $row) {
                $creditMinusDebit = bcsub((string) $row->credit, (string) $row->debit, self::Scale);
                $natural = $row->account_type === 'revenue' ? $creditMinusDebit : bcmul($creditMinusDebit, '-1', self::Scale);
                $groupTotal = bcadd($groupTotal, $creditMinusDebit, self::Scale);
                $accounts[] = [
                    'account_id' => (string) $row->account_id,
                    'account_code' => $row->account_code,
                    'account_name' => $row->account_name,
                    'account_type' => $row->account_type,
                    'debit' => $this->scaled((string) $row->debit),
                    'credit' => $this->scaled((string) $row->credit),
                    'amount' => $natural,
                ];
            }

            $total = bcadd($total, $groupTotal, self::Scale);
            $groups[] = [
                'dimension_id' => $dimensionId === '' ? null : $dimensionId,
                'dimension_code' => $names[$dimensionId]['code'] ?? null,
                'dimension_name' => $names[$dimensionId]['name'] ?? null,
                'accounts' => $accounts,
                'result' => $groupTotal,
            ];
        }

        return ['dimension' => $dimension, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'groups' => $groups, 'total' => $total];
    }

    /**
     * @return array{sections: array<string, array{accounts: list<array<string, mixed>>, total: string}>, totals: array<string, string>, is_balanced: bool}
     */
    private function balanceSheetAt(string $tenantId, CarbonImmutable $asOf): array
    {
        $sums = $this->sums($this->lines($tenantId)->where('journal_entries.posting_date', '<=', $asOf->toDateString()));
        $accounts = $this->accounts($tenantId, $sums->keys()->all());
        $sections = ['asset' => [], 'liability' => [], 'equity' => []];
        $totals = ['asset' => '0', 'liability' => '0', 'equity' => '0'];
        $unclosedResult = '0';

        foreach ($accounts as $account) {
            $sum = $sums->get((string) $account->id);
            $creditMinusDebit = bcsub((string) $sum->credit, (string) $sum->debit, self::Scale);

            if (in_array($account->account_type, LedgerBalanceReader::IncomeTypes, true)) {
                $unclosedResult = bcadd($unclosedResult, $creditMinusDebit, self::Scale);

                continue;
            }

            $section = match ($account->account_type) {
                'asset', 'wip' => 'asset',
                'liability' => 'liability',
                'equity' => 'equity',
                default => null,
            };

            if ($section === null) {
                continue;
            }

            $amount = $section === 'asset' ? bcmul($creditMinusDebit, '-1', self::Scale) : $creditMinusDebit;
            $totals[$section] = bcadd($totals[$section], $amount, self::Scale);
            $sections[$section][] = [
                'account_id' => (string) $account->id,
                'account_code' => $account->account_code,
                'account_name' => $account->name,
                'amount' => $amount,
            ];
        }

        $equityWithResult = bcadd($totals['equity'], $unclosedResult, self::Scale);
        $liabilitiesAndEquity = bcadd($totals['liability'], $equityWithResult, self::Scale);

        return [
            'sections' => [
                'asset' => ['accounts' => $sections['asset'], 'total' => $totals['asset']],
                'liability' => ['accounts' => $sections['liability'], 'total' => $totals['liability']],
                'equity' => ['accounts' => $sections['equity'], 'total' => $totals['equity']],
            ],
            'totals' => [
                'total_assets' => $totals['asset'],
                'total_liabilities' => $totals['liability'],
                'total_equity' => $totals['equity'],
                'result_not_yet_closed' => $unclosedResult,
                'total_equity_with_result' => $equityWithResult,
                'total_liabilities_and_equity' => $liabilitiesAndEquity,
            ],
            'is_balanced' => bccomp($totals['asset'], $liabilitiesAndEquity, self::Scale) === 0,
        ];
    }

    /**
     * @param  array{cost_center_id?: ?string, profit_center_id?: ?string, project_id?: ?string}  $dimensions
     * @return array{sections: array<string, array{accounts: list<array<string, mixed>>, total: string}>, totals: array<string, string>}
     */
    private function incomeStatementOf(string $tenantId, CarbonImmutable $from, CarbonImmutable $to, array $dimensions): array
    {
        $sums = $this->sums($this->lines($tenantId, $dimensions)
            ->whereBetween('journal_entries.posting_date', [$from->toDateString(), $to->toDateString()])
            ->where('journal_entries.journal_type', '!=', 'closing')
            ->whereIn('chart_of_accounts.account_type', LedgerBalanceReader::IncomeTypes));
        $sections = ['revenue' => [], 'cogs' => [], 'expense' => []];
        $totals = ['revenue' => '0', 'cogs' => '0', 'expense' => '0'];

        foreach ($this->accounts($tenantId, $sums->keys()->all()) as $account) {
            $sum = $sums->get((string) $account->id);
            $amount = $account->account_type === 'revenue'
                ? bcsub((string) $sum->credit, (string) $sum->debit, self::Scale)
                : bcsub((string) $sum->debit, (string) $sum->credit, self::Scale);
            $totals[$account->account_type] = bcadd($totals[$account->account_type], $amount, self::Scale);
            $sections[$account->account_type][] = [
                'account_id' => (string) $account->id,
                'account_code' => $account->account_code,
                'account_name' => $account->name,
                'amount' => $amount,
                'drill_down' => $this->drillDown((string) $account->id, $from, $to),
            ];
        }

        $grossProfit = bcsub($totals['revenue'], $totals['cogs'], self::Scale);

        return [
            'sections' => [
                'revenue' => ['accounts' => $sections['revenue'], 'total' => $totals['revenue']],
                'cogs' => ['accounts' => $sections['cogs'], 'total' => $totals['cogs']],
                'expense' => ['accounts' => $sections['expense'], 'total' => $totals['expense']],
            ],
            'totals' => [
                'total_revenue' => $totals['revenue'],
                'total_cogs' => $totals['cogs'],
                'gross_profit' => $grossProfit,
                'total_expenses' => $totals['expense'],
                'net_income' => bcsub($grossProfit, $totals['expense'], self::Scale),
            ],
        ];
    }

    /**
     * Puts the comparison amount and the variance next to each account of a section; accounts present only in the
     * comparison are listed with a zero current amount.
     *
     * @param  array<string, array{accounts: list<array<string, mixed>>, total: string}>  $current
     * @param  array<string, array{accounts: list<array<string, mixed>>, total: string}>|null  $comparison
     * @return array<string, array<string, mixed>>
     */
    private function mergeSections(array $current, ?array $comparison): array
    {
        if ($comparison === null) {
            return $current;
        }

        $merged = [];

        foreach ($current as $key => $section) {
            $previous = collect($comparison[$key]['accounts'] ?? [])->keyBy('account_id');
            $accounts = [];

            foreach ($section['accounts'] as $row) {
                $before = (string) ($previous->get($row['account_id'])['amount'] ?? '0');
                $accounts[] = $row + ['comparison_amount' => $this->scaled($before), 'variance' => bcsub($row['amount'], $before, self::Scale)];
                $previous->forget($row['account_id']);
            }

            foreach ($previous as $row) {
                $accounts[] = array_merge($row, ['amount' => '0.0000', 'comparison_amount' => $row['amount'], 'variance' => bcmul($row['amount'], '-1', self::Scale)]);
                unset($accounts[array_key_last($accounts)]['drill_down']);
            }

            $comparisonTotal = (string) ($comparison[$key]['total'] ?? '0');
            $merged[$key] = ['accounts' => $accounts, 'total' => $section['total'], 'comparison_total' => $this->scaled($comparisonTotal), 'variance' => bcsub($section['total'], $comparisonTotal, self::Scale)];
        }

        return $merged;
    }

    /**
     * Posted lines of the statutory ledger of a tenant, without opening entries, optionally narrowed to dimensions.
     *
     * @param  array{cost_center_id?: ?string, profit_center_id?: ?string, project_id?: ?string}  $dimensions
     */
    private function lines(string $tenantId, array $dimensions = []): Builder
    {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_lines.account_id', '=', 'chart_of_accounts.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_lines.tenant_id', $tenantId)
            ->whereNull('journal_entries.deleted_at')
            ->whereNull('journal_lines.deleted_at')
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->where('journal_entries.ledger_type', 'statutory')
            ->where('journal_entries.journal_type', '!=', 'opening');

        foreach (['cost_center_id', 'profit_center_id', 'project_id'] as $dimension) {
            if (! empty($dimensions[$dimension])) {
                $query->where("journal_lines.{$dimension}", $dimensions[$dimension]);
            }
        }

        return $query;
    }

    /**
     * @return Collection<string, \stdClass>
     */
    private function sums(Builder $query): Collection
    {
        return $query
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit_amount) as debit, SUM(journal_lines.credit_amount) as credit')
            ->get()
            ->keyBy(static fn (object $row): string => (string) $row->account_id);
    }

    /**
     * @param  list<string>  $ids
     * @return Collection<int, ChartOfAccount>
     */
    private function accounts(string $tenantId, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('id', $ids)->orderBy('account_code')->get();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{code: ?string, name: ?string}>
     */
    private function dimensionNames(string $tenantId, string $dimension, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $table = match ($dimension) {
            'cost_center' => 'cost_centers',
            'profit_center' => 'profit_centers',
            default => 'projects',
        };

        $codeColumn = $table === 'projects' ? 'project_number' : 'code';

        return DB::table($table)
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(static fn (object $row): array => [(string) $row->id => ['code' => isset($row->{$codeColumn}) ? (string) $row->{$codeColumn} : null, 'name' => isset($row->name) ? (string) $row->name : null]])
            ->all();
    }

    private function balanceOf(?object $sum): string
    {
        return $sum === null ? '0.0000' : bcsub((string) $sum->debit, (string) $sum->credit, self::Scale);
    }

    private function scaled(string $amount): string
    {
        return bcadd($amount, '0', self::Scale);
    }

    /**
     * Query of the account ledger that explains a figure.
     */
    private function drillDown(string $accountId, CarbonImmutable $from, CarbonImmutable $to): string
    {
        return "/api/v1/general-ledger/reports/account-ledger?account_id={$accountId}&from={$from->toDateString()}&to={$to->toDateString()}";
    }
}
