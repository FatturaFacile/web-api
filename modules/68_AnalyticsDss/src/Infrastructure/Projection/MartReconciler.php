<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Infrastructure\Projection;

use Illuminate\Support\Facades\DB;

/**
 * Checksum reconciliation of the data mart against the General Ledger: for every account of the tenant the ledger balance
 * (debit minus credit in the accounting currency of posted lines) must equal the sum of the facts, and the daily snapshots
 * must carry the same total as the facts. A variance of 0.00 everywhere means the mart is accounting-exact.
 */
final class MartReconciler
{
    /**
     * @return array{variance: float, accounts_checked: int, mismatches: list<array{account_id: string, ledger: float, mart: float, variance: float}>, snapshot_variance: float}
     */
    public function reconcile(string $tenantId): array
    {
        $ledger = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.tenant_id', $tenantId)->whereIn('e.status', MartProjector::COUNTED_ENTRY_STATUSES)
            ->whereNull('e.deleted_at')->whereNull('l.deleted_at')
            ->selectRaw('l.account_id as account_id, SUM(COALESCE(l.functional_debit, l.debit_amount * l.exchange_rate, 0) - COALESCE(l.functional_credit, l.credit_amount * l.exchange_rate, 0)) as balance')
            ->groupBy('l.account_id')->pluck('balance', 'account_id');

        $mart = DB::table('fact_financial_transactions')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->selectRaw('account_id, SUM(amount_functional) as balance')->groupBy('account_id')->pluck('balance', 'account_id');

        $mismatches = [];

        foreach ($ledger->keys()->merge($mart->keys())->unique() as $accountId) {
            $left = round((float) ($ledger[$accountId] ?? 0.0), 2);
            $right = round((float) ($mart[$accountId] ?? 0.0), 2);

            if (abs($left - $right) >= 0.005) {
                $mismatches[] = ['account_id' => (string) $accountId, 'ledger' => $left, 'mart' => $right, 'variance' => round($right - $left, 2)];
            }
        }

        $snapshotCount = (int) DB::table('agg_executive_daily_snapshots')->where('tenant_id', $tenantId)->whereNull('deleted_at')->sum('transaction_count');
        $factCount = (int) DB::table('fact_financial_transactions')->where('tenant_id', $tenantId)->whereNull('deleted_at')->count();
        $snapshotVariance = (float) abs($snapshotCount - $factCount);

        return [
            'variance' => round(array_sum(array_map(static fn (array $m): float => abs($m['variance']), $mismatches)), 2),
            'accounts_checked' => $ledger->keys()->merge($mart->keys())->unique()->count(),
            'mismatches' => $mismatches,
            'snapshot_variance' => $snapshotVariance,
        ];
    }
}
