<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Consolidation;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\GeneralLedger\Enums\ConsolidationMethod;
use Modules\GeneralLedger\Models\ConsolidationAdjustmentLine;
use Modules\GeneralLedger\Models\ConsolidationRun;
use Modules\GeneralLedger\Models\ConsolidationRunBalance;
use Modules\GeneralLedger\Models\StatementScheme;
use Modules\GeneralLedger\Services\FinancialStatementService;

/**
 * The consolidated trial balance, balance sheet and income statement of a stored run. Consolidated amount of an
 * account code = the translated balances of the fully consolidated members plus the lines of the run's adjustments.
 * The statements are classified by account type, like the existing generateBalanceSheet / generateIncomeStatement;
 * `reclassified()` puts the same accounts in a scheme written by the users (StatementScheme), through the existing
 * reclassification of FinancialStatementService.
 *
 * Signs: the consolidated net of an account is debit minus credit in group currency. The result of the period is the
 * income accounts (year to date, see ConsolidationCalculator) and is shown inside equity, so assets equal liabilities
 * plus equity. Non-controlling interests are a split of equity, not an addition. Reading the figures of a run needs the
 * access to every member entity (ConsolidationAccessGuard).
 */
final readonly class ConsolidatedStatementService
{
    private const array DebitNormalTypes = ['asset', 'wip', 'cogs', 'expense'];

    public function __construct(
        private ConsolidationAccessGuard $guard,
        private FinancialStatementService $statements,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $balances  Rows of the fully consolidated members: member_tenant_id, account_code, account_name, account_type, group_net
     * @param  list<array<string, mixed>>  $adjustmentLines  Lines with account_code, account_name, account_type, debit, credit
     * @param  list<string>  $memberOrder  Tenant ids, parent first: the name and type of a code come from the first member that has it
     * @return array<string, array{account_code: string, account_name: string, account_type: string, aggregated_net: string, adjustments_net: string, net: string}>
     */
    public function consolidatedAccounts(array $balances, array $adjustmentLines, array $memberOrder): array
    {
        $order = array_flip($memberOrder);
        usort($balances, static fn (array $left, array $right): int => [$order[$left['member_tenant_id']] ?? PHP_INT_MAX, $left['account_code']] <=> [$order[$right['member_tenant_id']] ?? PHP_INT_MAX, $right['account_code']]);

        $accounts = [];

        foreach ($balances as $row) {
            $code = (string) $row['account_code'];
            $accounts[$code] ??= ['account_code' => $code, 'account_name' => (string) $row['account_name'], 'account_type' => (string) $row['account_type'], 'aggregated_net' => ConsolidationMath::zero(), 'adjustments_net' => ConsolidationMath::zero(), 'net' => ConsolidationMath::zero()];
            $accounts[$code]['aggregated_net'] = ConsolidationMath::add($accounts[$code]['aggregated_net'], (string) $row['group_net']);
        }

        foreach ($adjustmentLines as $line) {
            $code = (string) $line['account_code'];
            $accounts[$code] ??= ['account_code' => $code, 'account_name' => (string) $line['account_name'], 'account_type' => (string) $line['account_type'], 'aggregated_net' => ConsolidationMath::zero(), 'adjustments_net' => ConsolidationMath::zero(), 'net' => ConsolidationMath::zero()];
            $accounts[$code]['adjustments_net'] = ConsolidationMath::add($accounts[$code]['adjustments_net'], ConsolidationMath::sub((string) $line['debit'], (string) $line['credit']));
        }

        foreach ($accounts as $code => $account) {
            $accounts[$code]['net'] = ConsolidationMath::add($account['aggregated_net'], $account['adjustments_net']);
        }

        ksort($accounts, SORT_STRING);

        return $accounts;
    }

    /**
     * @param  array<string, array{account_code: string, account_name: string, account_type: string, aggregated_net: string, adjustments_net: string, net: string}>  $accounts
     * @return array{accounts: list<array<string, string>>, total_debit: string, total_credit: string, is_balanced: bool}
     */
    public function trialBalanceOf(array $accounts): array
    {
        $rows = [];
        $totalDebit = ConsolidationMath::zero();
        $totalCredit = ConsolidationMath::zero();

        foreach ($accounts as $account) {
            $debit = ConsolidationMath::isPositive($account['net']) ? $account['net'] : ConsolidationMath::zero();
            $credit = ConsolidationMath::isPositive($account['net']) ? ConsolidationMath::zero() : ConsolidationMath::abs($account['net']);
            $totalDebit = ConsolidationMath::add($totalDebit, $debit);
            $totalCredit = ConsolidationMath::add($totalCredit, $credit);
            $rows[] = [...$account, 'debit' => $debit, 'credit' => $credit];
        }

        return [
            'accounts' => $rows,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced' => ConsolidationMath::cmp($totalDebit, $totalCredit) === 0,
        ];
    }

    /**
     * @param  array<string, array{account_code: string, account_name: string, account_type: string, aggregated_net: string, adjustments_net: string, net: string}>  $accounts
     * @param  list<array{nci_net_assets: string, nci_profit: string}>  $nonControllingInterests
     * @return array<string, mixed>
     */
    public function balanceSheetOf(array $accounts, array $nonControllingInterests): array
    {
        $assets = $liabilities = $equity = [];
        $totals = ['assets' => ConsolidationMath::zero(), 'liabilities' => ConsolidationMath::zero(), 'reserves' => ConsolidationMath::zero()];
        $incomeNet = ConsolidationMath::zero();

        foreach ($accounts as $account) {
            $type = $account['account_type'];

            if (in_array($type, ['revenue', 'cogs', 'expense'], true)) {
                $incomeNet = ConsolidationMath::add($incomeNet, $account['net']);

                continue;
            }

            if (in_array($type, ['asset', 'wip'], true)) {
                $assets[] = $this->line($account, $account['net']);
                $totals['assets'] = ConsolidationMath::add($totals['assets'], $account['net']);
            } elseif ($type === 'liability') {
                $liabilities[] = $this->line($account, ConsolidationMath::neg($account['net']));
                $totals['liabilities'] = ConsolidationMath::add($totals['liabilities'], ConsolidationMath::neg($account['net']));
            } elseif ($type === 'equity') {
                $equity[] = $this->line($account, ConsolidationMath::neg($account['net']));
                $totals['reserves'] = ConsolidationMath::add($totals['reserves'], ConsolidationMath::neg($account['net']));
            }
        }

        $result = ConsolidationMath::neg($incomeNet);
        $totalEquity = ConsolidationMath::add($totals['reserves'], $result);
        $nci = $this->sum($nonControllingInterests, 'nci_net_assets');
        $liabilitiesAndEquity = ConsolidationMath::add($totals['liabilities'], $totalEquity);

        return [
            'assets' => $assets,
            'total_assets' => $totals['assets'],
            'liabilities' => $liabilities,
            'total_liabilities' => $totals['liabilities'],
            'equity' => $equity,
            'total_equity_reserves' => $totals['reserves'],
            'result_of_the_period' => $result,
            'total_equity' => $totalEquity,
            'non_controlling_interests' => $nci,
            'equity_attributable_to_owners' => ConsolidationMath::sub($totalEquity, $nci),
            'total_liabilities_and_equity' => $liabilitiesAndEquity,
            'is_balanced' => ConsolidationMath::cmp($totals['assets'], $liabilitiesAndEquity) === 0,
        ];
    }

    /**
     * @param  array<string, array{account_code: string, account_name: string, account_type: string, aggregated_net: string, adjustments_net: string, net: string}>  $accounts
     * @param  list<array{nci_net_assets: string, nci_profit: string}>  $nonControllingInterests
     * @return array<string, mixed>
     */
    public function incomeStatementOf(array $accounts, array $nonControllingInterests): array
    {
        $revenues = $costOfSales = $expenses = [];
        $totals = ['revenue' => ConsolidationMath::zero(), 'cogs' => ConsolidationMath::zero(), 'expense' => ConsolidationMath::zero()];

        foreach ($accounts as $account) {
            match ($account['account_type']) {
                'revenue' => $this->addIncome($revenues, $totals, 'revenue', $account, ConsolidationMath::neg($account['net'])),
                'cogs' => $this->addIncome($costOfSales, $totals, 'cogs', $account, $account['net']),
                'expense' => $this->addIncome($expenses, $totals, 'expense', $account, $account['net']),
                default => null,
            };
        }

        $grossProfit = ConsolidationMath::sub($totals['revenue'], $totals['cogs']);
        $netIncome = ConsolidationMath::sub($grossProfit, $totals['expense']);
        $nci = $this->sum($nonControllingInterests, 'nci_profit');

        return [
            'revenues' => $revenues,
            'total_revenue' => $totals['revenue'],
            'cogs' => $costOfSales,
            'total_cogs' => $totals['cogs'],
            'gross_profit' => $grossProfit,
            'operating_expenses' => $expenses,
            'total_operating_expenses' => $totals['expense'],
            'net_income' => $netIncome,
            'net_income_attributable_to_non_controlling_interests' => $nci,
            'net_income_attributable_to_owners' => ConsolidationMath::sub($netIncome, $nci),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function trialBalance(User $user, ConsolidationRun $run): array
    {
        return [...$this->header($user, $run), ...$this->trialBalanceOf($this->accountsOf($run))];
    }

    /**
     * @return array<string, mixed>
     */
    public function balanceSheet(User $user, ConsolidationRun $run): array
    {
        return [...$this->header($user, $run), ...$this->balanceSheetOf($this->accountsOf($run), $this->nciOf($run))];
    }

    /**
     * @return array<string, mixed>
     */
    public function incomeStatement(User $user, ConsolidationRun $run): array
    {
        return [...$this->header($user, $run), ...$this->incomeStatementOf($this->accountsOf($run), $this->nciOf($run))];
    }

    /**
     * The consolidated accounts in a reclassification scheme of the parent entity. A balance sheet scheme also sees
     * the result of the period as the synthetic account CONS-RESULT, an equity account.
     *
     * @return array<string, mixed>
     */
    public function reclassified(User $user, ConsolidationRun $run, StatementScheme $scheme): array
    {
        $accounts = $this->accountsOf($run);
        $isBalanceSheet = $scheme->statement_type === 'balance_sheet';
        $selected = new Collection;
        $incomeNet = ConsolidationMath::zero();

        foreach ($accounts as $account) {
            $isIncome = in_array($account['account_type'], ['revenue', 'cogs', 'expense'], true);

            if ($isIncome) {
                $incomeNet = ConsolidationMath::add($incomeNet, $account['net']);
            }

            if ($isIncome !== $isBalanceSheet && in_array($account['account_type'], ['asset', 'wip', 'liability', 'equity', 'revenue', 'cogs', 'expense'], true)) {
                $selected->put($account['account_code'], $this->reclassifiable($account['account_code'], $account['account_name'], $account['account_type'], $account['net']));
            }
        }

        if ($isBalanceSheet && ! ConsolidationMath::isZero($incomeNet)) {
            $selected->put('CONS-RESULT', $this->reclassifiable('CONS-RESULT', 'Result of the period', 'equity', $incomeNet));
        }

        return [
            ...$this->header($user, $run),
            'scheme' => $scheme->name,
            'statement_type' => $scheme->statement_type,
            ...$this->statements->reclassifyAccounts($scheme, $selected),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function header(User $user, ConsolidationRun $run): array
    {
        $this->guard->assertCanReadGroup($user, $run->group()->withoutGlobalScopes()->firstOrFail());

        return [
            'run_id' => (string) $run->id,
            'version' => $run->version,
            'status' => $run->status,
            'currency' => $run->group_currency,
            'period_start' => $run->period_start->toDateString(),
            'period_end' => $run->period_end->toDateString(),
            'amounts_basis' => 'Balance sheet accounts at the period end; income accounts year to date.',
        ];
    }

    /**
     * @return array<string, array{account_code: string, account_name: string, account_type: string, aggregated_net: string, adjustments_net: string, net: string}>
     */
    private function accountsOf(ConsolidationRun $run): array
    {
        $balances = ConsolidationRunBalance::withoutGlobalScopes()
            ->where('run_id', $run->id)
            ->where('tenant_id', $run->tenant_id)
            ->where('consolidation_method', ConsolidationMethod::Full->value)
            ->get()
            ->map(static fn (ConsolidationRunBalance $row): array => $row->only(['member_tenant_id', 'account_code', 'account_name', 'account_type', 'group_net']))
            ->all();

        $lines = ConsolidationAdjustmentLine::withoutGlobalScopes()
            ->where('run_id', $run->id)
            ->where('tenant_id', $run->tenant_id)
            ->get()
            ->map(static fn (ConsolidationAdjustmentLine $line): array => $line->only(['account_code', 'account_name', 'account_type', 'debit', 'credit']))
            ->all();

        $order = array_map(static fn (array $member): string => (string) $member['tenant_id'], $run->members_snapshot);

        return $this->consolidatedAccounts($balances, $lines, $order);
    }

    /**
     * @return list<array{nci_net_assets: string, nci_profit: string}>
     */
    private function nciOf(ConsolidationRun $run): array
    {
        return array_values($run->totals['non_controlling_interests'] ?? []);
    }

    /**
     * @param  array{account_code: string, account_name: string, account_type: string, aggregated_net: string, adjustments_net: string, net: string}  $account
     * @return array{account_code: string, account_name: string, amount: string}
     */
    private function line(array $account, string $amount): array
    {
        return [
            'account_code' => $account['account_code'],
            'account_name' => $account['account_name'],
            'amount' => $amount,
        ];
    }

    /**
     * @param  list<array<string, string>>  $section
     * @param  array<string, string>  $totals
     * @param  array{account_code: string, account_name: string, account_type: string, aggregated_net: string, adjustments_net: string, net: string}  $account
     */
    private function addIncome(array &$section, array &$totals, string $key, array $account, string $amount): void
    {
        $section[] = $this->line($account, $amount);
        $totals[$key] = ConsolidationMath::add($totals[$key], $amount);
    }

    /**
     * @param  list<array<string, string>>  $items
     */
    private function sum(array $items, string $key): string
    {
        $sum = ConsolidationMath::zero();

        foreach ($items as $item) {
            $sum = ConsolidationMath::add($sum, (string) $item[$key]);
        }

        return $sum;
    }

    private function reclassifiable(string $code, string $name, string $type, string $net): object
    {
        $isDebitNormal = in_array($type, self::DebitNormalTypes, true);

        return (object) [
            'account_code' => $code,
            'account_name' => $name,
            'normal_balance' => $isDebitNormal ? 'debit' : 'credit',
            'debit' => ConsolidationMath::isPositive($net) ? $net : ConsolidationMath::zero(),
            'credit' => ConsolidationMath::isPositive($net) ? ConsolidationMath::zero() : ConsolidationMath::abs($net),
        ];
    }
}
