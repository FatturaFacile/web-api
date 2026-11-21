<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Infrastructure\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\KpiDashboards\Domain\ValueObjects\CockpitFilter;

/**
 * Sums of the posted ledger in the accounting currency, shared by the finance KPIs. The same query runs on SQLite and on
 * PostgreSQL: grouping and sums only, no engine-specific SQL.
 */
final class LedgerAggregator
{
    /**
     * Posted journal lines joined with their entry and account, scoped to the tenant, the legal entities and the cost
     * centers of the filter.
     */
    public function lines(CockpitFilter $filter): Builder
    {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_lines.account_id', '=', 'chart_of_accounts.id')
            ->where('journal_entries.tenant_id', $filter->tenantId)
            ->where('journal_lines.tenant_id', $filter->tenantId)
            ->whereNull('journal_entries.deleted_at')
            ->whereNull('journal_lines.deleted_at')
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->where('journal_entries.ledger_type', 'statutory')
            ->where('journal_entries.journal_type', '!=', 'opening');

        if ($filter->companyIds !== []) {
            $query->whereIn('journal_entries.company_id', $filter->companyIds);
        }

        if ($filter->costCenterIds !== []) {
            $query->whereIn('journal_lines.cost_center_id', $filter->costCenterIds);
        }

        return $query;
    }

    /**
     * Net of the accounts of the given types (and reporting class) between two dates: credit minus debit for revenue,
     * debit minus credit otherwise. A null `$from` sums every posting up to `$to` (a balance).
     *
     * @param  list<string>  $types
     */
    public function net(CockpitFilter $filter, array $types, ?CarbonImmutable $from, CarbonImmutable $to, ?string $reportingClass = null, bool $creditNormal = false): float
    {
        $net = 0.0;

        foreach ($this->sums($filter, $from, $to) as $row) {
            if (in_array($row['account_type'], $types, true) && ($reportingClass === null || $row['reporting_class'] === $reportingClass)) {
                $net += $row['debit'] - $row['credit'];
            }
        }

        return round($creditNormal ? -$net : $net, 2);
    }

    /** @var array<string, list<array{account_type: string, reporting_class: string|null, debit: float, credit: float}>> */
    private array $sumsMemo = [];

    /**
     * Debit and credit of the posted ledger by account type and reporting class for a window. One grouped scan serves every
     * KPI that asks for the same window (EBITDA alone needs revenue, COGS, expenses and the non-cash share), so a cockpit
     * reads the ledger once per window instead of once per figure.
     *
     * @return list<array{account_type: string, reporting_class: string|null, debit: float, credit: float}>
     */
    private function sums(CockpitFilter $filter, ?CarbonImmutable $from, CarbonImmutable $to): array
    {
        $key = implode('|', [$filter->tenantId, implode(',', $filter->companyIds), implode(',', $filter->costCenterIds), $from?->toDateString() ?? '-', $to->toDateString()]);

        if (isset($this->sumsMemo[$key])) {
            return $this->sumsMemo[$key];
        }

        $query = $this->lines($filter)->whereDate('journal_entries.posting_date', '<=', $to->toDateString());

        if ($from !== null) {
            $query->whereDate('journal_entries.posting_date', '>=', $from->toDateString());
        }

        $rows = $query
            ->selectRaw('chart_of_accounts.account_type as account_type, chart_of_accounts.reporting_class as reporting_class, COALESCE(SUM(COALESCE(journal_lines.functional_debit, journal_lines.debit_amount)), 0) as debit, COALESCE(SUM(COALESCE(journal_lines.functional_credit, journal_lines.credit_amount)), 0) as credit')
            ->groupBy('chart_of_accounts.account_type', 'chart_of_accounts.reporting_class')
            ->get();

        return $this->sumsMemo[$key] = $rows->map(static fn (object $row): array => [
            'account_type' => (string) $row->account_type,
            'reporting_class' => $row->reporting_class !== null ? (string) $row->reporting_class : null,
            'debit' => (float) $row->debit,
            'credit' => (float) $row->credit,
        ])->all();
    }

    /**
     * Net of the revenue, COGS and expense accounts per month ('Y-m'), for trends: amounts with their natural sign.
     *
     * @return array<string, array{revenue: float, cogs: float, expense: float}>
     */
    public function monthlyIncome(CockpitFilter $filter, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = [];

        for ($cursor = $from->startOfMonth(); $cursor <= $to; $cursor = $cursor->addMonth()) {
            $months[$cursor->format('Y-m')] = ['revenue' => 0.0, 'cogs' => 0.0, 'expense' => 0.0];
        }

        $rows = $this->lines($filter)
            ->whereIn('chart_of_accounts.account_type', ['revenue', 'cogs', 'expense'])
            ->whereDate('journal_entries.posting_date', '>=', $from->toDateString())
            ->whereDate('journal_entries.posting_date', '<=', $to->toDateString())
            ->selectRaw('journal_entries.posting_date as posting_date, chart_of_accounts.account_type as account_type, COALESCE(SUM(COALESCE(journal_lines.functional_debit, journal_lines.debit_amount)), 0) as debit, COALESCE(SUM(COALESCE(journal_lines.functional_credit, journal_lines.credit_amount)), 0) as credit')
            ->groupBy('journal_entries.posting_date', 'chart_of_accounts.account_type')
            ->get();

        foreach ($rows as $row) {
            $month = CarbonImmutable::parse($row->posting_date)->format('Y-m');
            $type = (string) $row->account_type;
            $amount = $type === 'revenue' ? (float) $row->credit - (float) $row->debit : (float) $row->debit - (float) $row->credit;
            $months[$month][$type] = round(($months[$month][$type] ?? 0.0) + $amount, 2);
        }

        return $months;
    }
}
