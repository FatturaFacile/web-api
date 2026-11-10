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
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;

final class FinancialStatementService implements FinancialStatementServiceInterface
{
    /**
     * Decimal places of every amount and ratio computed with bcmath.
     */
    private const int Scale = 4;

    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly LedgerBalanceReader $balances,
        private readonly LedgerReportService $reports,
    ) {}

    /**
     * Debit and credit per account of the posted entries of one fiscal period (statutory ledger). A reversed entry
     * and its reversal are both read, so they cancel out. Amounts are decimal strings at 4 decimals, in the accounting
     * currency of the tenant (`currency`): a foreign-currency line counts at its converted value.
     *
     * @return array{period_id: string, period_name: string, currency: string, total_debit: string, total_credit: string, is_balanced: bool, accounts: list<array<string, mixed>>}
     */
    public function generateTrialBalance(string $tenantId, string $fiscalPeriodId): array
    {
        $period = FiscalPeriod::findOrFail($fiscalPeriodId);

        $results = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_lines.account_id', '=', 'chart_of_accounts.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->whereNull('journal_entries.deleted_at')
            ->whereNull('journal_lines.deleted_at')
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->where('journal_entries.ledger_type', 'statutory')
            ->where('journal_entries.fiscal_period_id', $fiscalPeriodId)
            ->select(
                'chart_of_accounts.id as account_id',
                'chart_of_accounts.account_code',
                'chart_of_accounts.name as account_name',
                'chart_of_accounts.account_type',
                'chart_of_accounts.normal_balance',
                DB::raw('SUM(COALESCE(journal_lines.functional_debit, journal_lines.debit_amount)) as total_debit'),
                DB::raw('SUM(COALESCE(journal_lines.functional_credit, journal_lines.credit_amount)) as total_credit')
            )
            ->groupBy('chart_of_accounts.id', 'chart_of_accounts.account_code', 'chart_of_accounts.name', 'chart_of_accounts.account_type', 'chart_of_accounts.normal_balance')
            ->orderBy('chart_of_accounts.account_code')
            ->get();

        $totalDebit = '0';
        $totalCredit = '0';
        $accounts = [];

        foreach ($results as $row) {
            $debit = bcadd((string) $row->total_debit, '0', self::Scale);
            $credit = bcadd((string) $row->total_credit, '0', self::Scale);
            $totalDebit = bcadd($totalDebit, $debit, self::Scale);
            $totalCredit = bcadd($totalCredit, $credit, self::Scale);

            $accounts[] = [
                'account_id' => (string) $row->account_id,
                'account_code' => $row->account_code,
                'account_name' => $row->account_name,
                'account_type' => $row->account_type,
                'normal_balance' => $row->normal_balance,
                'debit' => $debit,
                'credit' => $credit,
                'net_balance' => $row->normal_balance === 'debit' ? bcsub($debit, $credit, self::Scale) : bcsub($credit, $debit, self::Scale),
            ];
        }

        $isBalanced = bccomp($totalDebit, $totalCredit, self::Scale) === 0;

        $this->dispatcher->publishAndDispatch(new TrialBalanceGeneratedEvent(
            fiscalPeriodId: (string) $period->id,
            totalDebit: (float) $totalDebit,
            totalCredit: (float) $totalCredit,
            isBalanced: $isBalanced,
            tenantId: $tenantId,
            companyId: (string) ($period->company_id ?? '')
        ));

        return [
            'period_id' => (string) $period->id,
            'period_name' => (string) $period->period_name,
            'currency' => TenantAccountingCurrency::of($tenantId),
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced' => $isBalanced,
            'accounts' => $accounts,
        ];
    }

    /**
     * Balance sheet at the end of a fiscal period: every posting up to that day (opening entries left out, since they
     * repeat balances already in the ledger), classified by account type only. The result not yet closed into equity
     * is added to equity, so the identity assets = liabilities + equity holds.
     *
     * @return array<string, mixed>
     */
    public function generateBalanceSheet(string $tenantId, string $fiscalPeriodId, ?string $presentationCurrency = null): array
    {
        $period = FiscalPeriod::findOrFail($fiscalPeriodId);
        $sheet = $this->reports->balanceSheet(
            $tenantId,
            CarbonImmutable::parse($period->end_date),
            null,
            $presentationCurrency,
            CarbonImmutable::parse($period->start_date)
        );
        $rows = static fn (string $section): array => array_map(static fn (array $row): array => [
            'account_id' => $row['account_id'],
            'account_code' => $row['account_code'],
            'account_name' => $row['account_name'],
            'net_balance' => $row['amount'],
            'functional_net_balance' => $row['functional_amount'] ?? $row['amount'],
            'presentation_net_balance' => $row['presentation_amount'] ?? $row['amount'],
            'exchange_rate' => $row['exchange_rate'] ?? '1.000000',
        ], $sheet['sections'][$section]['accounts']);
        $totals = $sheet['totals'];
        $functionalTotals = $sheet['functional_totals'] ?? $totals;
        $presentationTotals = $sheet['presentation_totals'] ?? $totals;

        $this->dispatcher->publishAndDispatch(new FinancialStatementGeneratedEvent(
            statementType: 'balance_sheet',
            fiscalPeriodId: (string) $period->id,
            netAmount: (float) $totals['total_assets'],
            tenantId: $tenantId,
            companyId: (string) ($period->company_id ?? '')
        ));

        return [
            'period_id' => (string) $period->id,
            'period_name' => (string) $period->period_name,
            'currency' => $sheet['currency'],
            'functional_currency' => $sheet['functional_currency'] ?? $sheet['currency'],
            'presentation_currency' => $sheet['presentation_currency'] ?? $sheet['currency'],
            'closing_exchange_rate' => $sheet['closing_exchange_rate'] ?? '1.000000',
            'average_exchange_rate' => $sheet['average_exchange_rate'] ?? '1.000000',
            'historical_exchange_rate' => $sheet['historical_exchange_rate'] ?? '1.000000',
            'currency_translation_adjustment' => $sheet['currency_translation_adjustment'] ?? '0.0000',
            'as_of' => $sheet['as_of'],
            'assets' => $rows('asset'),
            'total_assets' => $totals['total_assets'],
            'functional_total_assets' => $functionalTotals['total_assets'],
            'presentation_total_assets' => $presentationTotals['total_assets'],
            'liabilities' => $rows('liability'),
            'total_liabilities' => $totals['total_liabilities'],
            'functional_total_liabilities' => $functionalTotals['total_liabilities'],
            'presentation_total_liabilities' => $presentationTotals['total_liabilities'],
            'equity' => $rows('equity'),
            'total_equity_base' => $totals['total_equity'],
            'functional_total_equity_base' => $functionalTotals['total_equity'],
            'presentation_total_equity_base' => $presentationTotals['total_equity'],
            'current_period_net_income' => $totals['result_not_yet_closed'],
            'functional_current_period_net_income' => $functionalTotals['result_not_yet_closed'],
            'presentation_current_period_net_income' => $presentationTotals['result_not_yet_closed'],
            'total_equity' => $totals['total_equity_with_result'],
            'functional_total_equity' => $functionalTotals['total_equity_with_result'],
            'presentation_total_equity' => $presentationTotals['total_equity_with_result'],
            'total_liabilities_and_equity' => $totals['total_liabilities_and_equity'],
            'functional_total_liabilities_and_equity' => $functionalTotals['total_liabilities_and_equity'],
            'presentation_total_liabilities_and_equity' => $presentationTotals['total_liabilities_and_equity'],
            'functional_totals' => $functionalTotals,
            'presentation_totals' => $presentationTotals,
            'is_balanced' => $sheet['is_balanced'],
        ];
    }

    /**
     * Income statement of one fiscal period, classified by account type only (revenue, cogs, expense), without the
     * year-end closing entry.
     *
     * @return array<string, mixed>
     */
    public function generateIncomeStatement(string $tenantId, string $fiscalPeriodId, ?string $presentationCurrency = null): array
    {
        $period = FiscalPeriod::findOrFail($fiscalPeriodId);
        $statement = $this->reports->incomeStatement(
            $tenantId,
            CarbonImmutable::parse($period->start_date),
            CarbonImmutable::parse($period->end_date),
            null,
            null,
            [],
            $presentationCurrency
        );
        $rows = static fn (string $section): array => array_map(static fn (array $row): array => [
            'account_id' => $row['account_id'],
            'account_code' => $row['account_code'],
            'account_name' => $row['account_name'],
            'net_balance' => $row['amount'],
            'functional_net_balance' => $row['functional_amount'] ?? $row['amount'],
            'presentation_net_balance' => $row['presentation_amount'] ?? $row['amount'],
            'exchange_rate' => $row['exchange_rate'] ?? '1.000000',
        ], $statement['sections'][$section]['accounts']);
        $totals = $statement['totals'];
        $functionalTotals = $statement['functional_totals'] ?? $totals;
        $presentationTotals = $statement['presentation_totals'] ?? $totals;

        $this->dispatcher->publishAndDispatch(new FinancialStatementGeneratedEvent(
            statementType: 'income_statement',
            fiscalPeriodId: (string) $period->id,
            netAmount: (float) $totals['net_income'],
            tenantId: $tenantId,
            companyId: (string) ($period->company_id ?? '')
        ));

        return [
            'period_id' => (string) $period->id,
            'period_name' => (string) $period->period_name,
            'currency' => $statement['currency'],
            'functional_currency' => $statement['functional_currency'] ?? $statement['currency'],
            'presentation_currency' => $statement['presentation_currency'] ?? $statement['currency'],
            'closing_exchange_rate' => $statement['closing_exchange_rate'] ?? '1.000000',
            'average_exchange_rate' => $statement['average_exchange_rate'] ?? '1.000000',
            'currency_translation_adjustment' => $statement['currency_translation_adjustment'] ?? '0.0000',
            'revenues' => $rows('revenue'),
            'total_revenue' => $totals['total_revenue'],
            'functional_total_revenue' => $functionalTotals['total_revenue'],
            'presentation_total_revenue' => $presentationTotals['total_revenue'],
            'cogs' => $rows('cogs'),
            'total_cogs' => $totals['total_cogs'],
            'functional_total_cogs' => $functionalTotals['total_cogs'],
            'presentation_total_cogs' => $presentationTotals['total_cogs'],
            'gross_profit' => $totals['gross_profit'],
            'functional_gross_profit' => $functionalTotals['gross_profit'],
            'presentation_gross_profit' => $presentationTotals['gross_profit'],
            'operating_expenses' => $rows('expense'),
            'total_operating_expenses' => $totals['total_expenses'],
            'functional_total_operating_expenses' => $functionalTotals['total_expenses'],
            'presentation_total_operating_expenses' => $presentationTotals['total_expenses'],
            'net_income' => $totals['net_income'],
            'functional_net_income' => $functionalTotals['net_income'],
            'presentation_net_income' => $presentationTotals['net_income'],
            'functional_totals' => $functionalTotals,
            'presentation_totals' => $presentationTotals,
        ];
    }

    /**
     * Indirect operating cash flow of one period: net income corrected by the change of the customer and supplier
     * control accounts. Investing and financing flows are not derived here (null): the full statement is
     * cashFlowStatement().
     *
     * @return array<string, mixed>
     */
    public function generateCashFlowPreparation(string $tenantId, string $fiscalPeriodId): array
    {
        $period = FiscalPeriod::findOrFail($fiscalPeriodId);
        $netIncome = (string) $this->generateIncomeStatement($tenantId, $fiscalPeriodId)['net_income'];

        $controlChange = fn (string $reconciliationType): string => bcadd((string) (DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_lines.account_id', '=', 'chart_of_accounts.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->whereNull('journal_entries.deleted_at')
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->where('journal_entries.ledger_type', 'statutory')
            ->where('journal_entries.fiscal_period_id', $fiscalPeriodId)
            ->where('chart_of_accounts.reconciliation_type', $reconciliationType)
            ->selectRaw('COALESCE(SUM(COALESCE(journal_lines.functional_debit, journal_lines.debit_amount) - COALESCE(journal_lines.functional_credit, journal_lines.credit_amount)), 0) as change')
            ->value('change') ?? '0'), '0', self::Scale);

        $receivablesIncrease = $controlChange('customer');
        $payablesIncrease = bcmul($controlChange('supplier'), '-1', self::Scale);
        $operatingCashFlow = bcadd(bcsub($netIncome, $receivablesIncrease, self::Scale), $payablesIncrease, self::Scale);

        $this->dispatcher->publishAndDispatch(new FinancialStatementGeneratedEvent(
            statementType: 'cash_flow',
            fiscalPeriodId: (string) $period->id,
            netAmount: (float) $operatingCashFlow,
            tenantId: $tenantId,
            companyId: (string) ($period->company_id ?? '')
        ));

        return [
            'period_id' => (string) $period->id,
            'period_name' => (string) $period->period_name,
            'net_income' => $netIncome,
            'adjustments' => [
                'change_in_accounts_receivable' => bcmul($receivablesIncrease, '-1', self::Scale),
                'change_in_accounts_payable' => $payablesIncrease,
            ],
            'operating_cash_flow' => $operatingCashFlow,
            'investing_cash_flow' => null,
            'financing_cash_flow' => null,
            'net_cash_flow' => null,
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
