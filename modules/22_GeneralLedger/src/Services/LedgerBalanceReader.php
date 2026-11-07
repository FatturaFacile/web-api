<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads account balances of the statutory ledger as decimal strings. A reversed entry stays in the sums together with
 * its reversal, so the two cancel out. Opening entries repeat balances that are already in the ledger, so cumulative
 * reads leave them out.
 */
final class LedgerBalanceReader
{
    private const int Scale = 4;

    /**
     * Account types of the balance sheet. Memo and statistical accounts belong to no statement.
     *
     * @var list<string>
     */
    public const array BalanceSheetTypes = ['asset', 'liability', 'equity', 'wip'];

    /**
     * @var list<string>
     */
    public const array IncomeTypes = ['revenue', 'cogs', 'expense'];

    /**
     * Net natural balance per account for the postings between two dates, without opening and closing entries.
     *
     * @return Collection<string, object>
     */
    public function movements(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->byAccount(
            $this->postedLines($tenantId)
                ->whereBetween('journal_entries.posting_date', [$from->toDateString(), $to->toDateString()])
                ->whereNotIn('journal_entries.journal_type', ['opening', 'closing'])
        );
    }

    /**
     * Balance of the balance sheet accounts at a date, built from every posting up to it. Closing entries of earlier
     * years stay in: they carry past results into equity. The closing entry of the year itself stays out, because the
     * result of the year is reported on its own.
     *
     * @return Collection<string, object>
     */
    public function cumulative(string $tenantId, CarbonImmutable $yearStart, CarbonImmutable $to): Collection
    {
        return $this->byAccount(
            $this->postedLines($tenantId)
                ->where('journal_entries.posting_date', '<=', $to->toDateString())
                ->where('journal_entries.journal_type', '!=', 'opening')
                ->where(static fn (Builder $query): Builder => $query
                    ->where('journal_entries.journal_type', '!=', 'closing')
                    ->orWhere('journal_entries.posting_date', '<', $yearStart->toDateString()))
                ->whereIn('chart_of_accounts.account_type', self::BalanceSheetTypes)
        );
    }

    /**
     * Debit minus credit at a date, closing entries included, for the opening balances of the next year.
     *
     * @return Collection<string, object>
     */
    public function closedBalances(string $tenantId, CarbonImmutable $to): Collection
    {
        return $this->byAccount(
            $this->postedLines($tenantId)
                ->where('journal_entries.posting_date', '<=', $to->toDateString())
                ->where('journal_entries.journal_type', '!=', 'opening')
                ->whereIn('chart_of_accounts.account_type', self::BalanceSheetTypes)
        );
    }

    /**
     * Actual amounts per account, cost centre and period number of a fiscal year, in the natural sign of each account.
     *
     * @param  list<string>  $periodIds
     * @return Collection<int, object{account_id: string, cost_center_id: ?string, period_number: int, normal_balance: string, debit: string, credit: string}>
     */
    public function actualsByPeriod(string $tenantId, array $periodIds): Collection
    {
        return $this->postedLines($tenantId)
            ->whereIn('journal_entries.fiscal_period_id', $periodIds)
            ->whereNotIn('journal_entries.journal_type', ['opening', 'closing'])
            ->join('fiscal_periods', 'fiscal_periods.id', '=', 'journal_entries.fiscal_period_id')
            ->groupBy('chart_of_accounts.id', 'journal_lines.cost_center_id', 'fiscal_periods.period_number', 'chart_of_accounts.normal_balance')
            ->selectRaw('chart_of_accounts.id as account_id, journal_lines.cost_center_id, fiscal_periods.period_number, chart_of_accounts.normal_balance, SUM(journal_lines.debit_amount) as debit, SUM(journal_lines.credit_amount) as credit')
            ->get();
    }

    /**
     * Balance in the natural sign of the account: positive when it sits on its normal side.
     */
    public function naturalAmount(object $row): string
    {
        return $row->normal_balance === 'debit'
            ? bcsub((string) $row->debit, (string) $row->credit, self::Scale)
            : bcsub((string) $row->credit, (string) $row->debit, self::Scale);
    }

    private function postedLines(string $tenantId): Builder
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_lines.account_id', '=', 'chart_of_accounts.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->whereNull('journal_entries.deleted_at')
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->where('journal_entries.ledger_type', 'statutory');
    }

    /**
     * @return Collection<string, object>
     */
    private function byAccount(Builder $query): Collection
    {
        return $query
            ->groupBy('chart_of_accounts.id', 'chart_of_accounts.account_code', 'chart_of_accounts.name', 'chart_of_accounts.account_type', 'chart_of_accounts.normal_balance', 'chart_of_accounts.reporting_class', 'chart_of_accounts.reconciliation_type')
            ->orderBy('chart_of_accounts.account_code')
            ->selectRaw('chart_of_accounts.id as account_id, chart_of_accounts.account_code, chart_of_accounts.name as account_name, chart_of_accounts.account_type, chart_of_accounts.normal_balance, chart_of_accounts.reporting_class, chart_of_accounts.reconciliation_type, SUM(journal_lines.debit_amount) as debit, SUM(journal_lines.credit_amount) as credit')
            ->get()
            ->keyBy('account_id');
    }
}
