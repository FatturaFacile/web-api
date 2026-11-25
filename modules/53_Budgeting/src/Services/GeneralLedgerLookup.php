<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Budgeting\Contracts\LedgerLookup;

/**
 * Default ledger lookup: posted journal lines, the chart of accounts, the cost centres and the projects of the general
 * ledger. It is the only class of the budgeting module that knows the general ledger tables. Reversed entries stay in
 * the sum together with their reversal, so they net to zero.
 */
final class GeneralLedgerLookup implements LedgerLookup
{
    public function monthlyActuals(string $tenantId, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = DB::table('journal_lines as line')
            ->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->join('chart_of_accounts as account', 'account.id', '=', 'line.account_id')
            ->where('line.tenant_id', $tenantId)
            ->whereNull('line.deleted_at')
            ->whereNull('entry.deleted_at')
            ->whereIn('entry.status', ['posted', 'reversed'])
            ->whereDate('entry.posting_date', '>=', $from->toDateString())
            ->whereDate('entry.posting_date', '<=', $to->toDateString())
            ->select(['line.account_id', 'line.cost_center_id', 'line.project_id', 'entry.posting_date', 'line.debit_amount', 'line.credit_amount', 'account.normal_balance'])
            ->get();

        $sums = [];
        foreach ($rows as $row) {
            $month = Carbon::parse((string) $row->posting_date)->format('Y-m');
            $key = $row->account_id."\0".($row->cost_center_id ?? '')."\0".($row->project_id ?? '')."\0".$month;
            $net = $row->normal_balance === 'credit'
                ? bcsub((string) $row->credit_amount, (string) $row->debit_amount, 4)
                : bcsub((string) $row->debit_amount, (string) $row->credit_amount, 4);
            $sums[$key] ??= ['account_id' => (string) $row->account_id, 'cost_center_id' => $row->cost_center_id, 'project_id' => $row->project_id, 'month' => $month, 'amount' => '0'];
            $sums[$key]['amount'] = bcadd($sums[$key]['amount'], $net, 4);
        }

        return array_values(array_map(static fn (array $sum): array => [...$sum, 'amount' => bcround($sum['amount'], 2)], $sums));
    }

    public function accounts(string $tenantId, array $ids): array
    {
        $result = [];

        foreach (DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->whereIn('id', $ids)->get(['id', 'account_code', 'name', 'account_type', 'account_class']) as $row) {
            $kind = in_array('revenue', [$row->account_type, $row->account_class], true) ? 'revenue' : (in_array('expense', [$row->account_type, $row->account_class], true) ? 'expense' : 'other');
            $result[(string) $row->id] = ['code' => (string) $row->account_code, 'name' => (string) $row->name, 'kind' => $kind];
        }

        return $result;
    }

    public function costCenters(string $tenantId, array $ids): array
    {
        $result = [];

        foreach (DB::table('cost_centers')->where('tenant_id', $tenantId)->whereIn('id', $ids)->get(['id', 'code', 'name']) as $row) {
            $result[(string) $row->id] = ['code' => (string) $row->code, 'name' => (string) $row->name];
        }

        return $result;
    }

    public function idsByCode(string $tenantId, string $dimension, array $codes): array
    {
        [$table, $column] = match ($dimension) {
            'account' => ['chart_of_accounts', 'account_code'],
            'cost_center' => ['cost_centers', 'code'],
            default => ['projects', 'project_number'],
        };

        $result = [];

        foreach (DB::table($table)->where('tenant_id', $tenantId)->whereIn($column, $codes)->get(['id', $column]) as $row) {
            $result[(string) $row->{$column}] = (string) $row->id;
        }

        return $result;
    }

    public function projects(string $tenantId, array $ids): array
    {
        $result = [];

        foreach (DB::table('projects')->where('tenant_id', $tenantId)->whereIn('id', $ids)->get(['id', 'project_number', 'name']) as $row) {
            $result[(string) $row->id] = ['code' => (string) $row->project_number, 'name' => (string) $row->name];
        }

        return $result;
    }
}
