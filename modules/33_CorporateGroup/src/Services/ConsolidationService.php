<?php

declare(strict_types=1);

namespace Modules\CorporateGroup\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\CorporateGroup\Models\Company;
use Modules\CorporateGroup\Models\CompanyAccountingSetting;
use Modules\CorporateGroup\Models\ConsolidationElimination;
use Modules\CorporateGroup\Models\ConsolidationLine;
use Modules\CorporateGroup\Models\ConsolidationRun;
use Modules\CorporateGroup\Models\IntercompanyTransaction;

/**
 * Consolidates the trial balances of the companies of the group and removes the intercompany balances:
 * receivable/payable pairs and revenue/expense pairs of every intercompany transaction between included companies.
 *
 * Methods: `full` counts a company at 100%, `proportional` at its ownership percentage, `equity` is left out of the
 * line-by-line consolidation. Minority interests and unrealised intragroup margins are not computed.
 */
final class ConsolidationService
{
    /**
     * @param  array<string, mixed>  $params  period_start, period_end, name?, company_ids?, group_currency?, exchange_rates?
     */
    public function consolidate(string $tenantId, array $params, ?string $userId = null): ConsolidationRun
    {
        $from = Carbon::parse((string) $params['period_start'])->toDateString();
        $to = Carbon::parse((string) $params['period_end'])->toDateString();
        if ($to < $from) {
            throw new InvalidArgumentException('The period end cannot precede the period start.');
        }

        $companies = Company::query()
            ->where('is_active', true)
            ->when(! empty($params['company_ids']), fn ($q) => $q->whereIn('id', $params['company_ids']))
            ->get()
            ->filter(fn (Company $company): bool => $company->consolidation_method !== 'equity')
            ->keyBy('id');

        if ($companies->isEmpty()) {
            throw new InvalidArgumentException('No company to consolidate.');
        }

        $groupCurrency = strtoupper((string) ($params['group_currency'] ?? $companies->first()->currency));
        $rates = array_change_key_case((array) ($params['exchange_rates'] ?? []), CASE_UPPER);
        foreach ($companies as $company) {
            if ($company->currency !== $groupCurrency && ! isset($rates[$company->currency])) {
                throw new InvalidArgumentException("An exchange rate {$company->currency}→{$groupCurrency} is required to consolidate company {$company->code}.");
            }
        }

        $factorFor = function (Company $company) use ($groupCurrency, $rates): float {
            $rate = $company->currency === $groupCurrency ? 1.0 : (float) $rates[$company->currency];
            $share = $company->consolidation_method === 'proportional' ? (float) $company->ownership_percentage / 100 : 1.0;

            return $rate * $share;
        };

        return DB::transaction(function () use ($tenantId, $params, $userId, $from, $to, $companies, $groupCurrency, $rates, $factorFor): ConsolidationRun {
            $run = ConsolidationRun::create([
                'tenant_id' => $tenantId,
                'name' => (string) ($params['name'] ?? "Consolidation {$from} / {$to}"),
                'period_start' => $from,
                'period_end' => $to,
                'group_currency' => $groupCurrency,
                'company_ids' => $companies->keys()->values()->all(),
                'exchange_rates' => $rates,
                'created_by' => $userId,
            ]);

            /** @var array<string, array{name: string, type: string, normal: string, debit: float, credit: float, elim_debit: float, elim_credit: float, companies: array<string, array{debit: float, credit: float}>}> $accounts */
            $accounts = [];

            $rows = DB::table('journal_lines')
                ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
                ->join('chart_of_accounts', 'journal_lines.account_id', '=', 'chart_of_accounts.id')
                ->where('journal_entries.tenant_id', $tenantId)
                ->where('journal_entries.status', 'posted')
                ->whereNull('journal_entries.deleted_at')
                ->whereIn('journal_entries.company_id', $companies->keys()->all())
                ->where('journal_entries.posting_date', '>=', $from)
                ->where('journal_entries.posting_date', '<', Carbon::parse($to)->addDay()->toDateString())
                ->select(
                    'journal_entries.company_id',
                    'chart_of_accounts.account_code',
                    'chart_of_accounts.name as account_name',
                    'chart_of_accounts.account_type',
                    'chart_of_accounts.normal_balance',
                    DB::raw('SUM(journal_lines.debit_amount) as total_debit'),
                    DB::raw('SUM(journal_lines.credit_amount) as total_credit')
                )
                ->groupBy('journal_entries.company_id', 'chart_of_accounts.account_code', 'chart_of_accounts.name', 'chart_of_accounts.account_type', 'chart_of_accounts.normal_balance')
                ->get();

            foreach ($rows as $row) {
                $factor = $factorFor($companies[(string) $row->company_id]);
                $debit = (float) $row->total_debit * $factor;
                $credit = (float) $row->total_credit * $factor;
                $code = (string) $row->account_code;

                $accounts[$code] ??= ['name' => (string) $row->account_name, 'type' => (string) $row->account_type, 'normal' => (string) $row->normal_balance, 'debit' => 0.0, 'credit' => 0.0, 'elim_debit' => 0.0, 'elim_credit' => 0.0, 'companies' => []];
                $accounts[$code]['debit'] += $debit;
                $accounts[$code]['credit'] += $credit;
                $accounts[$code]['companies'][(string) $row->company_id] = ['debit' => round($debit, 4), 'credit' => round($credit, 4)];
            }

            $eliminated = $this->eliminate($run, $tenantId, $companies->keys()->all(), $from, $to, $accounts, $factorFor, $companies);

            foreach ($accounts as $code => $a) {
                $netDebit = $a['debit'] + $a['elim_debit'];
                $netCredit = $a['credit'] + $a['elim_credit'];

                ConsolidationLine::create([
                    'tenant_id' => $tenantId,
                    'run_id' => $run->id,
                    'account_code' => (string) $code,
                    'account_name' => $a['name'],
                    'account_type' => $a['type'],
                    'normal_balance' => $a['normal'],
                    'aggregated_debit' => round($a['debit'], 4),
                    'aggregated_credit' => round($a['credit'], 4),
                    'elimination_debit' => round($a['elim_debit'], 4),
                    'elimination_credit' => round($a['elim_credit'], 4),
                    'consolidated_debit' => round($netDebit, 4),
                    'consolidated_credit' => round($netCredit, 4),
                    'company_breakdown' => $a['companies'],
                ]);
            }

            $run->summary = array_merge($this->statements($accounts), ['eliminated_transactions' => $eliminated]);
            $run->save();

            return $run->load(['lines', 'eliminations']);
        });
    }

    /**
     * Reportistica consolidata: trial balance, income statement and balance sheet of a stored run.
     *
     * @return array<string, mixed>
     */
    public function report(ConsolidationRun $run): array
    {
        $run->loadMissing(['lines', 'eliminations']);

        $trial = $run->lines->map(fn (ConsolidationLine $l): array => [
            'account_code' => $l->account_code,
            'account_name' => $l->account_name,
            'account_type' => $l->account_type,
            'aggregated_debit' => (float) $l->aggregated_debit,
            'aggregated_credit' => (float) $l->aggregated_credit,
            'elimination_debit' => (float) $l->elimination_debit,
            'elimination_credit' => (float) $l->elimination_credit,
            'consolidated_debit' => (float) $l->consolidated_debit,
            'consolidated_credit' => (float) $l->consolidated_credit,
            'companies' => $l->company_breakdown,
        ])->values()->all();

        return [
            'run' => [
                'id' => $run->id,
                'name' => $run->name,
                'period_start' => $run->period_start->toDateString(),
                'period_end' => $run->period_end->toDateString(),
                'group_currency' => $run->group_currency,
                'company_ids' => $run->company_ids,
            ],
            'trial_balance' => [
                'accounts' => $trial,
                'total_debit' => round(array_sum(array_column($trial, 'consolidated_debit')), 4),
                'total_credit' => round(array_sum(array_column($trial, 'consolidated_credit')), 4),
            ],
            'eliminations' => $run->eliminations->map(fn (ConsolidationElimination $e): array => [
                'type' => $e->elimination_type,
                'transaction_id' => $e->intercompany_transaction_id,
                'debit_account_code' => $e->debit_account_code,
                'credit_account_code' => $e->credit_account_code,
                'amount' => (float) $e->amount,
                'description' => $e->description,
            ])->values()->all(),
            'income_statement' => $run->summary['income_statement'] ?? [],
            'balance_sheet' => $run->summary['balance_sheet'] ?? [],
        ];
    }

    /**
     * @param  list<string>  $companyIds
     * @param  array<string, mixed>  $accounts
     * @param  \Closure(Company): float  $factorFor
     * @param  Collection<string, Company>  $companies
     */
    private function eliminate(ConsolidationRun $run, string $tenantId, array $companyIds, string $from, string $to, array &$accounts, \Closure $factorFor, $companies): int
    {
        $transactions = IntercompanyTransaction::query()
            ->where('status', 'posted')
            ->whereIn('seller_company_id', $companyIds)
            ->whereIn('buyer_company_id', $companyIds)
            ->where('transaction_date', '>=', $from)
            ->where('transaction_date', '<', Carbon::parse($to)->addDay()->toDateString())
            ->get();

        $settings = CompanyAccountingSetting::query()->whereIn('company_id', $companyIds)->get()->keyBy('company_id');
        $codeOf = fn (?string $accountId): ?string => $accountId === null ? null : DB::table('chart_of_accounts')->where('id', $accountId)->value('account_code');

        $post = function (string $type, IntercompanyTransaction $tx, ?string $debitCode, ?string $creditCode, float $amount, string $description) use ($run, $tenantId, &$accounts): void {
            if ($debitCode === null || $creditCode === null || $amount <= 0) {
                return;
            }
            ConsolidationElimination::create([
                'tenant_id' => $tenantId,
                'run_id' => $run->id,
                'intercompany_transaction_id' => $tx->id,
                'elimination_type' => $type,
                'debit_account_code' => $debitCode,
                'credit_account_code' => $creditCode,
                'amount' => round($amount, 4),
                'description' => $description,
            ]);
            foreach ([[$debitCode, 'elim_debit'], [$creditCode, 'elim_credit']] as [$code, $side]) {
                $accounts[$code] ??= ['name' => $code, 'type' => 'asset', 'normal' => 'debit', 'debit' => 0.0, 'credit' => 0.0, 'elim_debit' => 0.0, 'elim_credit' => 0.0, 'companies' => []];
                $accounts[$code][$side] += $amount;
            }
        };

        $count = 0;
        foreach ($transactions as $tx) {
            $seller = $settings[(string) $tx->seller_company_id] ?? null;
            $buyer = $settings[(string) $tx->buyer_company_id] ?? null;
            if ($seller === null || $buyer === null) {
                continue;
            }

            // The elimination follows the share each side is consolidated at, so partly owned companies leave no residue.
            $sellerFactor = $factorFor($companies[(string) $tx->seller_company_id]);
            $buyerFactor = $factorFor($companies[(string) $tx->buyer_company_id]);
            $label = $tx->reference;

            // Receivable (seller) against payable (buyer), at gross: this is the open intragroup balance.
            // With different shares the two sides differ: each side is eliminated at its own share and the smaller side nets out.
            $gross = (float) $tx->gross_amount;
            $net = (float) $tx->net_amount;
            $payableCode = $codeOf($buyer->ic_payable_account_id);
            $receivableCode = $codeOf($seller->ic_receivable_account_id);
            $revenueCode = $codeOf($seller->ic_revenue_account_id);
            $expenseCode = $codeOf($buyer->ic_expense_account_id);

            $post('receivable_payable', $tx, $payableCode, $receivableCode, $gross * min($sellerFactor, $buyerFactor), "IC balance {$label}");
            $post('revenue_expense', $tx, $revenueCode, $expenseCode, $net * min($sellerFactor, $buyerFactor), "IC result {$label}");
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, array<string, mixed>>  $accounts
     * @return array<string, mixed>
     */
    private function statements(array $accounts): array
    {
        $income = ['revenue' => 0.0, 'expenses' => 0.0];
        $balance = ['assets' => 0.0, 'liabilities' => 0.0, 'equity' => 0.0];

        foreach ($accounts as $a) {
            $debit = $a['debit'] + $a['elim_debit'];
            $credit = $a['credit'] + $a['elim_credit'];

            match ($a['type']) {
                'revenue' => $income['revenue'] += $credit - $debit,
                'expense', 'cogs' => $income['expenses'] += $debit - $credit,
                'asset' => $balance['assets'] += $debit - $credit,
                'liability' => $balance['liabilities'] += $credit - $debit,
                'equity' => $balance['equity'] += $credit - $debit,
                default => null,
            };
        }

        $netIncome = $income['revenue'] - $income['expenses'];

        return [
            'income_statement' => [
                'total_revenue' => round($income['revenue'], 4),
                'total_expenses' => round($income['expenses'], 4),
                'net_income' => round($netIncome, 4),
            ],
            'balance_sheet' => [
                'total_assets' => round($balance['assets'], 4),
                'total_liabilities' => round($balance['liabilities'], 4),
                'total_equity' => round($balance['equity'], 4),
                'net_income' => round($netIncome, 4),
                'is_balanced' => abs($balance['assets'] - ($balance['liabilities'] + $balance['equity'] + $netIncome)) < 0.01,
            ],
        ];
    }
}
