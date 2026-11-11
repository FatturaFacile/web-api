<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Exceptions\LedgerPostingException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use RoundingMode;

/**
 * Reports read from the posted entries of the statutory ledger only: trial balance, account ledger (mastrino) with
 * running balance, balance sheet, income statement and analysis by dimension, each with an optional comparison range.
 *
 * Conventions shared with LedgerBalanceReader: an entry counts when it is posted or reversed (a reversed entry and its
 * reversal both stay, so they cancel out); opening entries (journal_type `opening`) repeat balances already in the
 * ledger and are left out; closing entries of the year-end are left out of the income statement and of the movements,
 * and kept in the cumulative balances, where they move past results into equity. Amounts are bcmath strings at 4
 * decimals; a balance is debit minus credit unless stated as natural (positive on the normal side of the account).
 *
 * Every amount is in the accounting (functional) currency of the tenant (FunctionalAmount): a line in a foreign
 * currency counts at its converted value, never at its transaction amount. Each report states that `currency`; the
 * account ledger also gives each line its transaction currency and amounts.
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
            'currency' => TenantAccountingCurrency::of($tenantId),
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

        $totals = $inRange()->selectRaw('COUNT(*) as line_count, COALESCE(SUM(COALESCE(journal_lines.functional_debit, journal_lines.debit_amount)), 0) as debit, COALESCE(SUM(COALESCE(journal_lines.functional_credit, journal_lines.credit_amount)), 0) as credit, COALESCE(SUM(journal_lines.debit_amount), 0) as transaction_debit, COALESCE(SUM(journal_lines.credit_amount), 0) as transaction_credit, COUNT(DISTINCT journal_lines.currency) as currency_count, MIN(journal_lines.currency) as single_currency')->first();
        $total = (int) ($totals->line_count ?? 0);
        $order = 'journal_entries.posting_date, journal_entries.journal_number, journal_entries.created_at, journal_lines.line_number, journal_lines.id';

        $rows = $inRange()
            ->selectRaw("journal_lines.id as line_id, journal_entries.id as journal_entry_id, journal_entries.entry_number, journal_entries.journal_number, journal_entries.posting_date, journal_entries.journal_type, journal_entries.status, journal_entries.source_document_type, journal_entries.source_document_id, journal_lines.description, journal_lines.partner_type, journal_lines.partner_id, journal_lines.cost_center_id, journal_lines.profit_center_id, journal_lines.project_id, journal_lines.currency, journal_lines.exchange_rate, journal_lines.debit_amount, journal_lines.credit_amount, COALESCE(journal_lines.functional_debit, journal_lines.debit_amount) as functional_debit, COALESCE(journal_lines.functional_credit, journal_lines.credit_amount) as functional_credit, SUM(COALESCE(journal_lines.functional_debit, journal_lines.debit_amount) - COALESCE(journal_lines.functional_credit, journal_lines.credit_amount)) OVER (ORDER BY {$order} ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) as cumulative")
            ->orderByRaw($order)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $currency = TenantAccountingCurrency::of($tenantId);
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
            'currency' => $currency,
            'debit' => $this->scaled((string) $row->functional_debit),
            'credit' => $this->scaled((string) $row->functional_credit),
            'transaction_currency' => $row->currency,
            'transaction_debit' => $this->scaled((string) $row->debit_amount),
            'transaction_credit' => $this->scaled((string) $row->credit_amount),
            'exchange_rate' => bcadd((string) $row->exchange_rate, '0', 6),
            'running_balance' => bcadd($opening, (string) $row->cumulative, self::Scale),
        ])->values()->all();

        $debit = (string) ($totals->debit ?? '0');
        $credit = (string) ($totals->credit ?? '0');

        return [
            'account' => ['id' => (string) $account->id, 'account_code' => $account->account_code, 'name' => $account->name, 'account_type' => $account->account_type, 'normal_balance' => $account->normal_balance],
            'currency' => $currency,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'opening_balance' => $opening,
            'closing_balance' => bcadd($opening, bcsub($debit, $credit, self::Scale), self::Scale),
            'total_debit' => $this->scaled($debit),
            'total_credit' => $this->scaled($credit),
            'transaction_totals' => (int) ($totals->currency_count ?? 0) === 1 ? ['currency' => (string) $totals->single_currency, 'debit' => $this->scaled((string) $totals->transaction_debit), 'credit' => $this->scaled((string) $totals->transaction_credit)] : null,
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
    public function balanceSheet(
        string $tenantId,
        CarbonImmutable $asOf,
        ?CarbonImmutable $compareAsOf = null,
        ?string $presentationCurrency = null,
        ?CarbonImmutable $periodStart = null,
    ): array {
        $functionalCurrency = TenantAccountingCurrency::of($tenantId);
        $targetCurrency = $presentationCurrency !== null && trim($presentationCurrency) !== ''
            ? strtoupper(trim($presentationCurrency))
            : $functionalCurrency;

        $current = $this->balanceSheetAt($tenantId, $asOf, $functionalCurrency, $targetCurrency, $periodStart);
        $comparison = $compareAsOf !== null
            ? $this->balanceSheetAt($tenantId, $compareAsOf, $functionalCurrency, $targetCurrency, null)
            : null;

        return [
            'currency' => $targetCurrency,
            'functional_currency' => $functionalCurrency,
            'presentation_currency' => $targetCurrency,
            'closing_exchange_rate' => $current['closing_exchange_rate'],
            'average_exchange_rate' => $current['average_exchange_rate'],
            'historical_exchange_rate' => $current['historical_exchange_rate'],
            'currency_translation_adjustment' => $current['currency_translation_adjustment'],
            'as_of' => $asOf->toDateString(),
            'compare_as_of' => $compareAsOf?->toDateString(),
            'sections' => $this->mergeSections($current['sections'], $comparison['sections'] ?? null),
            'totals' => $current['totals'],
            'functional_totals' => $current['functional_totals'],
            'presentation_totals' => $current['presentation_totals'],
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
    public function incomeStatement(
        string $tenantId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?CarbonImmutable $compareFrom = null,
        ?CarbonImmutable $compareTo = null,
        array $dimensions = [],
        ?string $presentationCurrency = null,
    ): array {
        $functionalCurrency = TenantAccountingCurrency::of($tenantId);
        $targetCurrency = $presentationCurrency !== null && trim($presentationCurrency) !== ''
            ? strtoupper(trim($presentationCurrency))
            : $functionalCurrency;

        $current = $this->incomeStatementOf($tenantId, $from, $to, $dimensions, $functionalCurrency, $targetCurrency);
        $comparison = $compareFrom !== null && $compareTo !== null
            ? $this->incomeStatementOf($tenantId, $compareFrom, $compareTo, $dimensions, $functionalCurrency, $targetCurrency)
            : null;

        return [
            'currency' => $targetCurrency,
            'functional_currency' => $functionalCurrency,
            'presentation_currency' => $targetCurrency,
            'closing_exchange_rate' => $current['closing_exchange_rate'],
            'average_exchange_rate' => $current['average_exchange_rate'],
            'currency_translation_adjustment' => $current['currency_translation_adjustment'],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'compare_from' => $compareFrom?->toDateString(),
            'compare_to' => $compareTo?->toDateString(),
            'sections' => $this->mergeSections($current['sections'], $comparison['sections'] ?? null),
            'totals' => $current['totals'],
            'functional_totals' => $current['functional_totals'],
            'presentation_totals' => $current['presentation_totals'],
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
            ->selectRaw("journal_lines.{$column} as dimension_id, chart_of_accounts.id as account_id, chart_of_accounts.account_code, chart_of_accounts.name as account_name, chart_of_accounts.account_type, chart_of_accounts.normal_balance, SUM(COALESCE(journal_lines.functional_debit, journal_lines.debit_amount)) as debit, SUM(COALESCE(journal_lines.functional_credit, journal_lines.credit_amount)) as credit")
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

        return ['currency' => TenantAccountingCurrency::of($tenantId), 'dimension' => $dimension, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'groups' => $groups, 'total' => $total];
    }

    /**
     * @return array<string, mixed>
     */
    private function balanceSheetAt(
        string $tenantId,
        CarbonImmutable $asOf,
        string $functionalCurrency,
        string $targetCurrency,
        ?CarbonImmutable $periodStart = null,
    ): array {
        $sums = $this->sums($this->lines($tenantId)->where('journal_entries.posting_date', '<=', $asOf->toDateString()));
        $accounts = $this->accounts($tenantId, $sums->keys()->all());

        $translate = $targetCurrency !== $functionalCurrency;
        $rangeStart = $periodStart ?? $this->resolveYearStart($tenantId, $asOf);
        $closingRate = $translate ? $this->closingTranslationRate($functionalCurrency, $targetCurrency, $asOf) : '1.000000';
        $historicalRate = $translate ? $this->historicalTranslationRate($functionalCurrency, $targetCurrency, $rangeStart, $closingRate) : '1.000000';
        $averageRate = $translate ? $this->averageTranslationRate($functionalCurrency, $targetCurrency, $rangeStart, $asOf, $closingRate) : '1.000000';

        $sections = ['asset' => [], 'liability' => [], 'equity' => []];
        $funcTotals = ['asset' => '0.0000', 'liability' => '0.0000', 'equity' => '0.0000'];
        $presTotals = ['asset' => '0.0000', 'liability' => '0.0000', 'equity' => '0.0000'];
        $unclosedResultFunc = '0.0000';

        foreach ($accounts as $account) {
            $sum = $sums->get((string) $account->id);
            $creditMinusDebit = bcsub((string) $sum->credit, (string) $sum->debit, self::Scale);

            if (in_array($account->account_type, LedgerBalanceReader::IncomeTypes, true)) {
                $unclosedResultFunc = bcadd($unclosedResultFunc, $creditMinusDebit, self::Scale);

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

            $funcAmount = $section === 'asset' ? bcmul($creditMinusDebit, '-1', self::Scale) : $creditMinusDebit;
            $rateForAccount = $section === 'equity' ? $historicalRate : $closingRate;
            $presAmount = $translate ? $this->translateAmount($funcAmount, $rateForAccount) : $funcAmount;

            $funcTotals[$section] = bcadd($funcTotals[$section], $funcAmount, self::Scale);
            $presTotals[$section] = bcadd($presTotals[$section], $presAmount, self::Scale);
            $sections[$section][] = [
                'account_id' => (string) $account->id,
                'account_code' => $account->account_code,
                'account_name' => $account->name,
                'amount' => $presAmount,
                'functional_amount' => $funcAmount,
                'presentation_amount' => $presAmount,
                'exchange_rate' => $rateForAccount,
            ];
        }

        $funcEquityWithResult = bcadd($funcTotals['equity'], $unclosedResultFunc, self::Scale);
        $funcLiabilitiesAndEquity = bcadd($funcTotals['liability'], $funcEquityWithResult, self::Scale);

        $unclosedResultPres = $translate ? $this->translateAmount($unclosedResultFunc, $averageRate) : $unclosedResultFunc;
        $cta = '0.0000';

        if ($translate) {
            // CO Art. 958d para. 3: CTA bridges closing-rate net assets and historical/average-rate equity components.
            $netAssetsPres = bcsub($presTotals['asset'], $presTotals['liability'], self::Scale);
            $equityBeforeCta = bcadd($presTotals['equity'], $unclosedResultPres, self::Scale);
            $cta = bcsub($netAssetsPres, $equityBeforeCta, self::Scale);
        }

        $presEquityWithResult = bcadd(bcadd($presTotals['equity'], $unclosedResultPres, self::Scale), $cta, self::Scale);
        $presLiabilitiesAndEquity = bcadd($presTotals['liability'], $presEquityWithResult, self::Scale);

        $functionalTotalsMap = [
            'total_assets' => $funcTotals['asset'],
            'total_liabilities' => $funcTotals['liability'],
            'total_equity' => $funcTotals['equity'],
            'result_not_yet_closed' => $unclosedResultFunc,
            'currency_translation_adjustment' => '0.0000',
            'total_equity_with_result' => $funcEquityWithResult,
            'total_liabilities_and_equity' => $funcLiabilitiesAndEquity,
        ];

        $presentationTotalsMap = [
            'total_assets' => $presTotals['asset'],
            'total_liabilities' => $presTotals['liability'],
            'total_equity' => $presTotals['equity'],
            'result_not_yet_closed' => $unclosedResultPres,
            'currency_translation_adjustment' => $cta,
            'total_equity_with_result' => $presEquityWithResult,
            'total_liabilities_and_equity' => $presLiabilitiesAndEquity,
        ];

        return [
            'closing_exchange_rate' => $closingRate,
            'average_exchange_rate' => $averageRate,
            'historical_exchange_rate' => $historicalRate,
            'currency_translation_adjustment' => $cta,
            'sections' => [
                'asset' => ['accounts' => $sections['asset'], 'total' => $presTotals['asset'], 'functional_total' => $funcTotals['asset']],
                'liability' => ['accounts' => $sections['liability'], 'total' => $presTotals['liability'], 'functional_total' => $funcTotals['liability']],
                'equity' => ['accounts' => $sections['equity'], 'total' => $presTotals['equity'], 'functional_total' => $funcTotals['equity']],
            ],
            'totals' => $presentationTotalsMap,
            'functional_totals' => $functionalTotalsMap,
            'presentation_totals' => $presentationTotalsMap,
            'is_balanced' => bccomp($presTotals['asset'], $presLiabilitiesAndEquity, self::Scale) === 0,
        ];
    }

    /**
     * @param  array{cost_center_id?: ?string, profit_center_id?: ?string, project_id?: ?string}  $dimensions
     * @return array<string, mixed>
     */
    private function incomeStatementOf(
        string $tenantId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $dimensions,
        string $functionalCurrency,
        string $targetCurrency,
    ): array {
        $sums = $this->sums($this->lines($tenantId, $dimensions)
            ->whereBetween('journal_entries.posting_date', [$from->toDateString(), $to->toDateString()])
            ->where('journal_entries.journal_type', '!=', 'closing')
            ->whereIn('chart_of_accounts.account_type', LedgerBalanceReader::IncomeTypes));

        $translate = $targetCurrency !== $functionalCurrency;
        $closingRate = $translate ? $this->closingTranslationRate($functionalCurrency, $targetCurrency, $to) : '1.000000';
        $averageRate = $translate ? $this->averageTranslationRate($functionalCurrency, $targetCurrency, $from, $to, $closingRate) : '1.000000';

        $sections = ['revenue' => [], 'cogs' => [], 'expense' => []];
        $funcTotals = ['revenue' => '0.0000', 'cogs' => '0.0000', 'expense' => '0.0000'];
        $presTotals = ['revenue' => '0.0000', 'cogs' => '0.0000', 'expense' => '0.0000'];

        foreach ($this->accounts($tenantId, $sums->keys()->all()) as $account) {
            $sum = $sums->get((string) $account->id);
            $funcAmount = $account->account_type === 'revenue'
                ? bcsub((string) $sum->credit, (string) $sum->debit, self::Scale)
                : bcsub((string) $sum->debit, (string) $sum->credit, self::Scale);
            $presAmount = $translate ? $this->translateAmount($funcAmount, $averageRate) : $funcAmount;

            $funcTotals[$account->account_type] = bcadd($funcTotals[$account->account_type], $funcAmount, self::Scale);
            $presTotals[$account->account_type] = bcadd($presTotals[$account->account_type], $presAmount, self::Scale);
            $sections[$account->account_type][] = [
                'account_id' => (string) $account->id,
                'account_code' => $account->account_code,
                'account_name' => $account->name,
                'amount' => $presAmount,
                'functional_amount' => $funcAmount,
                'presentation_amount' => $presAmount,
                'exchange_rate' => $averageRate,
                'drill_down' => $this->drillDown((string) $account->id, $from, $to),
            ];
        }

        $funcGrossProfit = bcsub($funcTotals['revenue'], $funcTotals['cogs'], self::Scale);
        $funcNetIncome = bcsub($funcGrossProfit, $funcTotals['expense'], self::Scale);

        $presGrossProfit = bcsub($presTotals['revenue'], $presTotals['cogs'], self::Scale);
        $presNetIncome = bcsub($presGrossProfit, $presTotals['expense'], self::Scale);

        $netIncomeAtClosing = $translate ? $this->translateAmount($funcNetIncome, $closingRate) : $funcNetIncome;
        $cta = $translate ? bcsub($netIncomeAtClosing, $presNetIncome, self::Scale) : '0.0000';

        $functionalTotalsMap = [
            'total_revenue' => $funcTotals['revenue'],
            'total_cogs' => $funcTotals['cogs'],
            'gross_profit' => $funcGrossProfit,
            'total_expenses' => $funcTotals['expense'],
            'net_income' => $funcNetIncome,
        ];

        $presentationTotalsMap = [
            'total_revenue' => $presTotals['revenue'],
            'total_cogs' => $presTotals['cogs'],
            'gross_profit' => $presGrossProfit,
            'total_expenses' => $presTotals['expense'],
            'net_income' => $presNetIncome,
        ];

        return [
            'closing_exchange_rate' => $closingRate,
            'average_exchange_rate' => $averageRate,
            'currency_translation_adjustment' => $cta,
            'sections' => [
                'revenue' => ['accounts' => $sections['revenue'], 'total' => $presTotals['revenue'], 'functional_total' => $funcTotals['revenue']],
                'cogs' => ['accounts' => $sections['cogs'], 'total' => $presTotals['cogs'], 'functional_total' => $funcTotals['cogs']],
                'expense' => ['accounts' => $sections['expense'], 'total' => $presTotals['expense'], 'functional_total' => $funcTotals['expense']],
            ],
            'totals' => $presentationTotalsMap,
            'functional_totals' => $functionalTotalsMap,
            'presentation_totals' => $presentationTotalsMap,
        ];
    }

    private function resolveYearStart(string $tenantId, CarbonImmutable $asOf): CarbonImmutable
    {
        $year = FiscalYear::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('start_date', '<=', $asOf->toDateString())
            ->whereDate('end_date', '>=', $asOf->toDateString())
            ->first();

        return $year !== null ? CarbonImmutable::parse((string) $year->start_date) : $asOf->startOfYear();
    }

    /**
     * Resolves presentation currency units per 1 functional currency unit at a given date (closing rate).
     */
    public function closingTranslationRate(string $functionalCurrency, string $presentationCurrency, CarbonImmutable $asOf): string
    {
        $direct = ExchangeRate::query()
            ->where('source', $functionalCurrency)
            ->where('target', $presentationCurrency)
            ->whereDate('effective_date', '<=', $asOf->toDateString())
            ->orderByDesc('effective_date')
            ->value('rate');

        if ($direct !== null && Decimal::isPositive((string) $direct)) {
            return bcadd((string) $direct, '0', 6);
        }

        $inverse = ExchangeRate::query()
            ->where('source', $presentationCurrency)
            ->where('target', $functionalCurrency)
            ->whereDate('effective_date', '<=', $asOf->toDateString())
            ->orderByDesc('effective_date')
            ->value('rate');

        if ($inverse !== null && Decimal::isPositive((string) $inverse)) {
            return bcadd(bcround(Decimal::div('1', (string) $inverse), 6, RoundingMode::HalfAwayFromZero), '0', 6);
        }

        throw new LedgerPostingException("No {$functionalCurrency}/{$presentationCurrency} exchange rate available on or before {$asOf->toDateString()}.");
    }

    public function historicalTranslationRate(string $functionalCurrency, string $presentationCurrency, CarbonImmutable $startDate, string $fallbackRate): string
    {
        $direct = ExchangeRate::query()
            ->where('source', $functionalCurrency)
            ->where('target', $presentationCurrency)
            ->whereDate('effective_date', '<=', $startDate->toDateString())
            ->orderByDesc('effective_date')
            ->value('rate');

        if ($direct !== null && Decimal::isPositive((string) $direct)) {
            return bcadd((string) $direct, '0', 6);
        }

        $inverse = ExchangeRate::query()
            ->where('source', $presentationCurrency)
            ->where('target', $functionalCurrency)
            ->whereDate('effective_date', '<=', $startDate->toDateString())
            ->orderByDesc('effective_date')
            ->value('rate');

        if ($inverse !== null && Decimal::isPositive((string) $inverse)) {
            return bcadd(bcround(Decimal::div('1', (string) $inverse), 6, RoundingMode::HalfAwayFromZero), '0', 6);
        }

        return $fallbackRate;
    }

    public function averageTranslationRate(
        string $functionalCurrency,
        string $presentationCurrency,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $fallbackRate,
    ): string {
        $directRates = ExchangeRate::query()
            ->where('source', $functionalCurrency)
            ->where('target', $presentationCurrency)
            ->whereBetween('effective_date', [$from->toDateString(), $to->toDateString()])
            ->pluck('rate')
            ->filter(static fn ($rate): bool => Decimal::isPositive((string) $rate))
            ->values();

        if ($directRates->isNotEmpty()) {
            $sum = '0';
            foreach ($directRates as $rate) {
                $sum = bcadd($sum, (string) $rate, 8);
            }

            return bcadd(bcround(Decimal::div($sum, (string) $directRates->count()), 6, RoundingMode::HalfAwayFromZero), '0', 6);
        }

        $inverseRates = ExchangeRate::query()
            ->where('source', $presentationCurrency)
            ->where('target', $functionalCurrency)
            ->whereBetween('effective_date', [$from->toDateString(), $to->toDateString()])
            ->pluck('rate')
            ->filter(static fn ($rate): bool => Decimal::isPositive((string) $rate))
            ->values();

        if ($inverseRates->isNotEmpty()) {
            $sum = '0';
            foreach ($inverseRates as $rate) {
                $sum = bcadd($sum, Decimal::div('1', (string) $rate), 8);
            }

            return bcadd(bcround(Decimal::div($sum, (string) $inverseRates->count()), 6, RoundingMode::HalfAwayFromZero), '0', 6);
        }

        return $fallbackRate;
    }

    private function translateAmount(string $functionalAmount, string $rate): string
    {
        return bcadd(bcround(bcmul($functionalAmount, $rate, 8), self::Scale, RoundingMode::HalfAwayFromZero), '0', self::Scale);
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
            ->selectRaw('journal_lines.account_id, SUM(COALESCE(journal_lines.functional_debit, journal_lines.debit_amount)) as debit, SUM(COALESCE(journal_lines.functional_credit, journal_lines.credit_amount)) as credit')
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
