<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Contracts\FinancialStatementServiceInterface;
use Modules\GeneralLedger\Events\FinancialStatementGeneratedEvent;
use Modules\GeneralLedger\Events\TrialBalanceGeneratedEvent;
use Modules\GeneralLedger\Models\FiscalPeriod;

final class FinancialStatementService implements FinancialStatementServiceInterface
{
    public function __construct(
        private readonly EventDispatcher $dispatcher
    ) {}

    public function generateTrialBalance(string $tenantId, string $fiscalPeriodId): array
    {
        $period = FiscalPeriod::findOrFail($fiscalPeriodId);

        $results = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_lines.account_id', '=', 'chart_of_accounts.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', 'posted')
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
}
