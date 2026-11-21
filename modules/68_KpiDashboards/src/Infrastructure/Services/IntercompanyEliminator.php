<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Infrastructure\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\KpiDashboards\Domain\ValueObjects\CockpitFilter;

/**
 * The intercompany amounts of the posted intercompany transactions (Mod 021) between two legal entities that are both
 * inside the selected scope. Each is counted on both sides of the group (seller revenue and receivable, buyer cost and
 * payable), so a consolidated view subtracts them; a single-entity view never does, because the counterparty is outside.
 */
final class IntercompanyEliminator
{
    public function __construct(private readonly KpiFx $fx) {}

    /**
     * Amounts in the accounting currency. `revenue`, `cogs` and `opex` are flows of the period, `receivable` and `payable`
     * the intercompany balances still open at the end of it (everything posted up to the end of the period).
     *
     * @return array{revenue: float, cogs: float, opex: float, receivable: float, payable: float}
     */
    public function eliminations(CockpitFilter $filter): array
    {
        $none = ['revenue' => 0.0, 'cogs' => 0.0, 'opex' => 0.0, 'receivable' => 0.0, 'payable' => 0.0];

        if (! $filter->eliminateIntercompany) {
            return $none;
        }

        $scope = $filter->companyIds !== []
            ? $filter->companyIds
            : DB::table('companies')->where('tenant_id', $filter->tenantId)->whereNull('deleted_at')->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();

        if (count($scope) < 2) {
            return $none;
        }

        $transactions = DB::table('intercompany_transactions')
            ->where('tenant_id', $filter->tenantId)
            ->where('status', 'posted')
            ->whereNull('deleted_at')
            ->whereIn('seller_company_id', $scope)
            ->whereIn('buyer_company_id', $scope)
            ->whereDate('transaction_date', '<=', $filter->to->toDateString())
            ->get();

        $totals = $none;

        foreach ($transactions as $transaction) {
            $date = CarbonImmutable::parse($transaction->transaction_date);
            $net = $this->fx->toAccounting($filter->tenantId, (string) $transaction->currency, (float) $transaction->net_amount, $date);
            $gross = $this->fx->toAccounting($filter->tenantId, (string) $transaction->currency, (float) $transaction->gross_amount, $date);

            $totals['receivable'] += $gross;
            $totals['payable'] += $gross;

            if ($date < $filter->from) {
                continue;
            }

            $totals['revenue'] += $net;
            $totals[$transaction->transaction_type === 'goods' ? 'cogs' : 'opex'] += $net;
        }

        return array_map(static fn (float $value): float => round($value, 2), $totals);
    }
}
