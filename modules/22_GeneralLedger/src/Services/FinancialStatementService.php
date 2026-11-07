<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Carbon\CarbonImmutable;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Contracts\FinancialStatementServiceInterface;
use Modules\GeneralLedger\Enums\ReportingClass;
use Modules\GeneralLedger\Events\FinancialStatementGeneratedEvent;
use Modules\GeneralLedger\Events\TrialBalanceGeneratedEvent;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\StatementScheme;

final class FinancialStatementService implements FinancialStatementServiceInterface
{
    /**
     * Decimal places of every amount and ratio computed with bcmath.
     */
    private const int Scale = 4;

    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly LedgerBalanceReader $balances,
    ) {}

    public function generateTrialBalance(string $tenantId, string $fiscalPeriodId): array
    {
        $period = FiscalPeriod::findOrFail($fiscalPeriodId);

        $results = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_lines.account_id', '=', 'chart_of_accounts.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.ledger_type', 'statutory')
            ->where('journal_entries.fiscal_period_id', $fiscalPeriodId)
            ->select(
                'chart_of_accounts.id as account_id',
                'chart_of_accounts.account_code',
                'chart_of_accounts.name as account_name',
                'chart_of_accounts.account_type',
                'chart_of_accounts.normal_balance',
                DB::raw('SUM(journal_lines.debit_amount) as total_debit'),
                DB::raw('SUM(journal_lines.credit_amount) as total_credit')
            )
            ->groupBy(
                'chart_of_accounts.id',
                'chart_of_accounts.account_code',
                'chart_of_accounts.name',
                'chart_of_accounts.account_type',
                'chart_of_accounts.normal_balance'
            )
            ->orderBy('chart_of_accounts.account_code')
            ->get();

        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $accounts = [];

        foreach ($results as $row) {
            $dr = (float) $row->total_debit;
            $cr = (float) $row->total_credit;
            $totalDebit += $dr;
            $totalCredit += $cr;

            $netBalance = $row->normal_balance === 'debit' ? ($dr - $cr) : ($cr - $dr);

            $accounts[] = [
                'account_id' => $row->account_id,
                'account_code' => $row->account_code,
                'account_name' => $row->account_name,
                'account_type' => $row->account_type,
                'normal_balance' => $row->normal_balance,
                'debit' => $dr,
                'credit' => $cr,
                'net_balance' => $netBalance,
            ];
        }

        $isBalanced = abs($totalDebit - $totalCredit) < 0.0001;

        $event = new TrialBalanceGeneratedEvent(
            fiscalPeriodId: $period->id,
            totalDebit: $totalDebit,
            totalCredit: $totalCredit,
            isBalanced: $isBalanced,
            tenantId: $tenantId,
            companyId: (string) ($period->company_id ?? '')
        );

        $this->dispatcher->publish($event);
        event($event);

        return [
            'period_id' => $period->id,
            'period_name' => $period->period_name,
            'total_debit' => round($totalDebit, 4),
            'total_credit' => round($totalCredit, 4),
            'is_balanced' => $isBalanced,
            'accounts' => $accounts,
        ];
    }

    public function generateBalanceSheet(string $tenantId, string $fiscalPeriodId): array
    {
        $trialBalance = $this->generateTrialBalance($tenantId, $fiscalPeriodId);
        $period = FiscalPeriod::findOrFail($fiscalPeriodId);

        $assets = [];
        $liabilities = [];
        $equity = [];

        $totalAssets = 0.0;
        $totalLiabilities = 0.0;
        $totalEquity = 0.0;

        $incomeStmt = $this->generateIncomeStatement($tenantId, $fiscalPeriodId);
        $netIncome = (float) $incomeStmt['net_income'];

        foreach ($trialBalance['accounts'] as $acc) {
            $type = strtolower((string) $acc['account_type']);
            $bal = (float) $acc['net_balance'];

            if ($type === 'asset' || str_starts_with((string) $acc['account_code'], '1')) {
                $assets[] = $acc;
                $totalAssets += $bal;
            } elseif ($type === 'liability' || str_starts_with((string) $acc['account_code'], '2')) {
                $liabilities[] = $acc;
                $totalLiabilities += $bal;
            } elseif ($type === 'equity' || str_starts_with((string) $acc['account_code'], '3')) {
                $equity[] = $acc;
                $totalEquity += $bal;
            }
        }

        // Add current period net income to equity retained earnings
        $totalEquityWithNetIncome = $totalEquity + $netIncome;
        $isBalanced = abs($totalAssets - ($totalLiabilities + $totalEquityWithNetIncome)) < 0.01;

        $event = new FinancialStatementGeneratedEvent(
            statementType: 'balance_sheet',
            fiscalPeriodId: $period->id,
            netAmount: $totalAssets,
            tenantId: $tenantId,
            companyId: (string) ($period->company_id ?? '')
        );
        $this->dispatcher->publish($event);
        event($event);

        return [
            'period_id' => $period->id,
            'period_name' => $period->period_name,
            'assets' => $assets,
            'total_assets' => round($totalAssets, 2),
            'liabilities' => $liabilities,
            'total_liabilities' => round($totalLiabilities, 2),
            'equity' => $equity,
            'total_equity_base' => round($totalEquity, 2),
            'current_period_net_income' => round($netIncome, 2),
            'total_equity' => round($totalEquityWithNetIncome, 2),
            'total_liabilities_and_equity' => round($totalLiabilities + $totalEquityWithNetIncome, 2),
            'is_balanced' => $isBalanced,
        ];
    }

    public function generateIncomeStatement(string $tenantId, string $fiscalPeriodId): array
    {
        $trialBalance = $this->generateTrialBalance($tenantId, $fiscalPeriodId);
        $period = FiscalPeriod::findOrFail($fiscalPeriodId);

        $revenues = [];
        $cogs = [];
        $expenses = [];

        $totalRevenue = 0.0;
        $totalCogs = 0.0;
        $totalExpenses = 0.0;

        foreach ($trialBalance['accounts'] as $acc) {
            $type = strtolower((string) $acc['account_type']);
            $bal = (float) $acc['net_balance'];

            if ($type === 'revenue' || str_starts_with((string) $acc['account_code'], '4')) {
                $revenues[] = $acc;
                $totalRevenue += $bal;
            } elseif ($type === 'cogs' || str_starts_with((string) $acc['account_code'], '5')) {
                $cogs[] = $acc;
                $totalCogs += $bal;
            } elseif ($type === 'expense' || str_starts_with((string) $acc['account_code'], '6')) {
                $expenses[] = $acc;
                $totalExpenses += $bal;
            }
        }

        $grossProfit = $totalRevenue - $totalCogs;
        $netIncome = $grossProfit - $totalExpenses;

        $event = new FinancialStatementGeneratedEvent(
            statementType: 'income_statement',
            fiscalPeriodId: $period->id,
            netAmount: $netIncome,
            tenantId: $tenantId,
            companyId: (string) ($period->company_id ?? '')
        );
        $this->dispatcher->publish($event);
        event($event);

        return [
            'period_id' => $period->id,
            'period_name' => $period->period_name,
            'revenues' => $revenues,
            'total_revenue' => round($totalRevenue, 2),
            'cogs' => $cogs,
            'total_cogs' => round($totalCogs, 2),
            'gross_profit' => round($grossProfit, 2),
            'operating_expenses' => $expenses,
            'total_operating_expenses' => round($totalExpenses, 2),
            'net_income' => round($netIncome, 2),
        ];
    }

    public function generateCashFlowPreparation(string $tenantId, string $fiscalPeriodId): array
    {
        $period = FiscalPeriod::findOrFail($fiscalPeriodId);
        $incomeStmt = $this->generateIncomeStatement($tenantId, $fiscalPeriodId);
        $netIncome = (float) $incomeStmt['net_income'];

        // Compute AR and AP changes
        $arChange = (float) DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_lines.account_id', '=', 'chart_of_accounts.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.fiscal_period_id', $fiscalPeriodId)
            ->where('chart_of_accounts.reconciliation_type', 'customer')
            ->sum(DB::raw('journal_lines.debit_amount - journal_lines.credit_amount'));

        $apChange = (float) DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_lines.account_id', '=', 'chart_of_accounts.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.fiscal_period_id', $fiscalPeriodId)
            ->where('chart_of_accounts.reconciliation_type', 'supplier')
            ->sum(DB::raw('journal_lines.credit_amount - journal_lines.debit_amount'));

        $operatingCashFlow = $netIncome - $arChange + $apChange;

        $event = new FinancialStatementGeneratedEvent(
            statementType: 'cash_flow',
            fiscalPeriodId: $period->id,
            netAmount: $operatingCashFlow,
            tenantId: $tenantId,
            companyId: (string) ($period->company_id ?? '')
        );
        $this->dispatcher->publish($event);
        event($event);

        return [
            'period_id' => $period->id,
            'period_name' => $period->period_name,
            'net_income' => round($netIncome, 2),
            'adjustments' => [
                'change_in_accounts_receivable' => round(-$arChange, 2),
                'change_in_accounts_payable' => round($apChange, 2),
            ],
            'operating_cash_flow' => round($operatingCashFlow, 2),
            'investing_cash_flow' => 0.00,
            'financing_cash_flow' => 0.00,
            'net_cash_flow' => round($operatingCashFlow, 2),
        ];
    }

    /**
     * Income statement and balance sheet of a fiscal year next to the year before.
     *
     * @return array<string, mixed>
     */
    public function comparativeStatements(string $tenantId, string $fiscalYearId): array
    {
        $year = FiscalYear::findOrFail($fiscalYearId);
        $previous = $this->previousYear($tenantId, $year);

        $current = $this->yearAmounts($tenantId, $year);
        $before = $previous === null ? ['income' => collect(), 'balance' => collect(), 'result' => '0'] : $this->yearAmounts($tenantId, $previous);

        return [
            'fiscal_year' => $year->year_code,
            'previous_fiscal_year' => $previous?->year_code,
            'income_statement' => $this->compare($current['income'], $before['income']),
            'balance_sheet' => $this->compare($current['balance'], $before['balance']),
            'result' => $this->comparisonOf($current['result'], $before['result']),
        ];
    }

    /**
     * A statement in the layout of a scheme defined by the users, next to the previous year. Accounts that no row picks
     * up are listed, so a gap in the scheme cannot go unnoticed.
     *
     * @return array<string, mixed>
     */
    public function reclassifiedStatement(string $tenantId, string $schemeId, string $fiscalYearId): array
    {
        $scheme = StatementScheme::with('rows')->findOrFail($schemeId);
        $year = FiscalYear::findOrFail($fiscalYearId);
        $previous = $this->previousYear($tenantId, $year);

        $key = $scheme->statement_type === 'balance_sheet' ? 'balance' : 'income';
        $current = $this->yearAmounts($tenantId, $year)[$key];
        $before = $previous === null ? collect() : $this->yearAmounts($tenantId, $previous)[$key];

        $currentRows = $this->schemeValues($scheme, $current);
        $previousRows = $this->schemeValues($scheme, $before);
        $rows = [];

        foreach ($scheme->rows as $row) {
            $rows[] = [
                'code' => $row->code,
                'label' => $row->label,
                'row_type' => $row->row_type,
                ...($row->row_type === 'heading'
                    ? ['current' => null, 'previous' => null, 'change' => null, 'change_percent' => null]
                    : $this->comparisonOf($currentRows[$row->code] ?? '0', $previousRows[$row->code] ?? '0')),
            ];
        }

        return [
            'scheme' => $scheme->name,
            'statement_type' => $scheme->statement_type,
            'fiscal_year' => $year->year_code,
            'previous_fiscal_year' => $previous?->year_code,
            'rows' => $rows,
            'unmapped_accounts' => $this->unmappedAccounts($scheme, $current),
        ];
    }

    /**
     * Cash flow statement of a fiscal year, indirect method: the result, corrected by the change of each class of
     * account. Because the ledger balances, the three sections must add up to the change in cash, and `is_reconciled`
     * proves it. Accounts classified only by their type are listed.
     *
     * @return array<string, mixed>
     */
    public function cashFlowStatement(string $tenantId, string $fiscalYearId): array
    {
        $year = FiscalYear::findOrFail($fiscalYearId);
        $from = CarbonImmutable::parse($year->start_date);
        $to = CarbonImmutable::parse($year->end_date);

        $movements = $this->balances->movements($tenantId, $from, $to);
        $changes = array_fill_keys(array_column(ReportingClass::cases(), 'value'), '0');
        $netIncome = '0';
        $assumed = [];

        foreach ($movements as $account) {
            $debitMinusCredit = bcsub((string) $account->debit, (string) $account->credit, self::Scale);

            if (in_array($account->account_type, LedgerBalanceReader::IncomeTypes, true)) {
                $netIncome = bcsub($netIncome, $debitMinusCredit, self::Scale);

                if ($account->reporting_class === ReportingClass::NonCashExpense->value) {
                    $changes[ReportingClass::NonCashExpense->value] = bcadd($changes[ReportingClass::NonCashExpense->value], $debitMinusCredit, self::Scale);
                }

                continue;
            }

            [$class, $isAssumed] = ReportingClass::of($account);

            if ($class === null) {
                continue;
            }

            $changes[$class->value] = bcadd($changes[$class->value], $debitMinusCredit, self::Scale);

            if ($isAssumed && bccomp($debitMinusCredit, '0', self::Scale) !== 0) {
                $assumed[] = ['account_code' => $account->account_code, 'assumed_class' => $class->value];
            }
        }

        $nonCash = $changes[ReportingClass::NonCashExpense->value];
        $workingCapital = [
            'receivables' => bcmul($changes[ReportingClass::Receivable->value], '-1', self::Scale),
            'inventory' => bcmul($changes[ReportingClass::Inventory->value], '-1', self::Scale),
            'other_current_assets' => bcmul($changes[ReportingClass::OtherCurrentAsset->value], '-1', self::Scale),
            'current_liabilities' => bcmul($changes[ReportingClass::CurrentLiability->value], '-1', self::Scale),
        ];

        $operating = bcadd($netIncome, $nonCash, self::Scale);

        foreach ($workingCapital as $change) {
            $operating = bcadd($operating, $change, self::Scale);
        }

        // The non-cash charge already reduced the asset it wrote down, so investing must not count it as a disposal.
        $investing = bcsub(bcmul($changes[ReportingClass::NonCurrentAsset->value], '-1', self::Scale), $nonCash, self::Scale);
        $financing = bcmul(bcadd($changes[ReportingClass::NonCurrentLiability->value], $changes[ReportingClass::Equity->value], self::Scale), '-1', self::Scale);

        $netChange = bcadd(bcadd($operating, $investing, self::Scale), $financing, self::Scale);
        $cashChange = $changes[ReportingClass::Cash->value];

        $event = new FinancialStatementGeneratedEvent(
            statementType: 'cash_flow_statement',
            fiscalPeriodId: (string) $year->id,
            netAmount: (float) $netChange,
            tenantId: $tenantId,
            companyId: (string) ($year->company_id ?? '')
        );
        $this->dispatcher->publish($event);
        event($event);

        return [
            'fiscal_year' => $year->year_code,
            'net_income' => $netIncome,
            'non_cash_expenses' => $nonCash,
            'working_capital_changes' => $workingCapital,
            'operating_cash_flow' => $operating,
            'investing_cash_flow' => $investing,
            'financing_cash_flow' => $financing,
            'net_cash_flow' => $netChange,
            'change_in_cash' => $cashChange,
            'is_reconciled' => bccomp($netChange, $cashChange, self::Scale) === 0,
            'assumed_classes' => $assumed,
        ];
    }

    /**
     * Liquidity and profitability ratios at the end of a fiscal year. A ratio without a meaningful denominator is null.
     *
     * @return array<string, mixed>
     */
    public function financialRatios(string $tenantId, string $fiscalYearId): array
    {
        $year = FiscalYear::findOrFail($fiscalYearId);
        $from = CarbonImmutable::parse($year->start_date);
        $to = CarbonImmutable::parse($year->end_date);

        $totals = array_fill_keys(array_column(ReportingClass::cases(), 'value'), '0');
        $assumed = [];

        foreach ($this->balances->cumulative($tenantId, $from, $to) as $account) {
            [$class, $isAssumed] = ReportingClass::of($account);

            if ($class === null) {
                continue;
            }

            $amount = $this->balances->naturalAmount($account);
            $totals[$class->value] = bcadd($totals[$class->value], $amount, self::Scale);

            if ($isAssumed && bccomp($amount, '0', self::Scale) !== 0) {
                $assumed[] = ['account_code' => $account->account_code, 'assumed_class' => $class->value];
            }
        }

        $revenue = '0';
        $cogs = '0';
        $expenses = '0';

        foreach ($this->balances->movements($tenantId, $from, $to) as $account) {
            $amount = $this->balances->naturalAmount($account);

            match ($account->account_type) {
                'revenue' => $revenue = bcadd($revenue, $amount, self::Scale),
                'cogs' => $cogs = bcadd($cogs, $amount, self::Scale),
                'expense' => $expenses = bcadd($expenses, $amount, self::Scale),
                default => null,
            };
        }

        $netIncome = bcsub(bcsub($revenue, $cogs, self::Scale), $expenses, self::Scale);
        $currentAssets = array_reduce(
            [ReportingClass::Cash, ReportingClass::Receivable, ReportingClass::Inventory, ReportingClass::OtherCurrentAsset],
            fn (string $sum, ReportingClass $class): string => bcadd($sum, $totals[$class->value], self::Scale),
            '0'
        );
        $currentLiabilities = $totals[ReportingClass::CurrentLiability->value];
        $liabilities = bcadd($currentLiabilities, $totals[ReportingClass::NonCurrentLiability->value], self::Scale);
        $equity = bcadd($totals[ReportingClass::Equity->value], $netIncome, self::Scale);
        $totalAssets = bcadd($currentAssets, $totals[ReportingClass::NonCurrentAsset->value], self::Scale);
        $quickAssets = bcsub($currentAssets, $totals[ReportingClass::Inventory->value], self::Scale);

        return [
            'fiscal_year' => $year->year_code,
            'liquidity' => [
                'working_capital' => bcsub($currentAssets, $currentLiabilities, self::Scale),
                'current_ratio' => $this->ratio($currentAssets, $currentLiabilities),
                'quick_ratio' => $this->ratio($quickAssets, $currentLiabilities),
                'cash_ratio' => $this->ratio($totals[ReportingClass::Cash->value], $currentLiabilities),
            ],
            'profitability' => [
                'gross_margin' => $this->ratio(bcsub($revenue, $cogs, self::Scale), $revenue),
                'net_margin' => $this->ratio($netIncome, $revenue),
                'return_on_assets' => $this->ratio($netIncome, $totalAssets),
                'return_on_equity' => $this->ratio($netIncome, $equity),
            ],
            'solvency' => [
                'debt_to_equity' => $this->ratio($liabilities, $equity),
            ],
            'assumed_classes' => $assumed,
        ];
    }

    private function ratio(string $numerator, string $denominator): ?string
    {
        return bccomp($denominator, '0', self::Scale) === 0 ? null : bcdiv($numerator, $denominator, self::Scale);
    }

    /**
     * The year that ends the day before this one starts.
     */
    private function previousYear(string $tenantId, FiscalYear $year): ?FiscalYear
    {
        return FiscalYear::where('tenant_id', $tenantId)
            ->whereDate('end_date', CarbonImmutable::parse($year->start_date)->subDay()->toDateString())
            ->first();
    }

    /**
     * @return array{income: Collection<string, object>, balance: Collection<string, object>, result: string}
     */
    private function yearAmounts(string $tenantId, FiscalYear $year): array
    {
        $from = CarbonImmutable::parse($year->start_date);
        $to = CarbonImmutable::parse($year->end_date);

        $income = $this->balances->movements($tenantId, $from, $to)
            ->filter(static fn (object $account): bool => in_array($account->account_type, LedgerBalanceReader::IncomeTypes, true));

        $result = '0';

        foreach ($income as $account) {
            $result = bcadd($result, bcsub((string) $account->credit, (string) $account->debit, self::Scale), self::Scale);
        }

        return ['income' => $income, 'balance' => $this->balances->cumulative($tenantId, $from, $to), 'result' => $result];
    }

    /**
     * @param  Collection<string, object>  $current
     * @param  Collection<string, object>  $previous
     * @return list<array<string, mixed>>
     */
    private function compare(Collection $current, Collection $previous): array
    {
        return $current->keys()->merge($previous->keys())->unique()
            ->map(function (string $accountId) use ($current, $previous): array {
                $account = $current->get($accountId) ?? $previous->get($accountId);

                return [
                    'account_code' => $account->account_code,
                    'account_name' => $account->account_name,
                    'account_type' => $account->account_type,
                    ...$this->comparisonOf(
                        $current->has($accountId) ? $this->balances->naturalAmount($current->get($accountId)) : '0',
                        $previous->has($accountId) ? $this->balances->naturalAmount($previous->get($accountId)) : '0'
                    ),
                ];
            })
            ->sortBy('account_code')
            ->values()
            ->all();
    }

    /**
     * @return array{current: string, previous: string, change: string, change_percent: ?string}
     */
    private function comparisonOf(string $current, string $previous): array
    {
        $change = bcsub($current, $previous, self::Scale);
        $base = ltrim($previous, '-');

        return [
            'current' => $current,
            'previous' => $previous,
            'change' => $change,
            'change_percent' => bccomp($base, '0', self::Scale) === 0 ? null : bcmul(bcdiv($change, $base, self::Scale + 2), '100', 2),
        ];
    }

    /**
     * Values of the rows of a scheme. Subtotals read earlier rows only, which the validation enforces, so one pass is
     * enough and no row can depend on itself.
     *
     * @param  Collection<string, object>  $accounts
     * @return array<string, string>
     */
    private function schemeValues(StatementScheme $scheme, Collection $accounts): array
    {
        $values = [];

        foreach ($scheme->rows as $row) {
            if ($row->row_type === 'accounts') {
                $sum = '0';

                foreach ($accounts as $account) {
                    if ($this->matchesPrefixes((string) $account->account_code, $row->account_prefixes ?? [])) {
                        $sum = bcadd($sum, $this->balances->naturalAmount($account), self::Scale);
                    }
                }

                $values[$row->code] = bcmul($sum, (string) $row->sign, self::Scale);
            } elseif ($row->row_type === 'subtotal') {
                $sum = '0';

                foreach ($row->formula ?? [] as $operand) {
                    $sum = bcadd($sum, bcmul($values[$operand['row']] ?? '0', (string) ($operand['sign'] ?? 1), self::Scale), self::Scale);
                }

                $values[$row->code] = bcmul($sum, (string) $row->sign, self::Scale);
            }
        }

        return $values;
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function matchesPrefixes(string $accountCode, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($accountCode, (string) $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<string, object>  $accounts
     * @return list<array{account_code: string, account_name: string, amount: string}>
     */
    private function unmappedAccounts(StatementScheme $scheme, Collection $accounts): array
    {
        $prefixes = $scheme->rows->where('row_type', 'accounts')->flatMap(static fn ($row): array => $row->account_prefixes ?? [])->all();

        return $accounts
            ->reject(fn (object $account): bool => $this->matchesPrefixes((string) $account->account_code, $prefixes))
            ->map(fn (object $account): array => [
                'account_code' => $account->account_code,
                'account_name' => $account->account_name,
                'amount' => $this->balances->naturalAmount($account),
            ])
            ->values()
            ->all();
    }
}
