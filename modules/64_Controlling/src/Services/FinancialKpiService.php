<?php

declare(strict_types=1);

namespace Modules\Controlling\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Controlling\Models\FinancialSnapshot;
use Modules\Controlling\Support\Decimal;

/**
 * Working capital and financial performance ratios from the monthly snapshots: DSO, DPO, net working capital (CCN),
 * inventory turnover, DIO and the cash conversion cycle (CCC = DIO + DSO - DPO). Averages use opening and closing balance
 * of the month; a ratio with an empty denominator is 0 and is reported in the warnings, never a division error.
 */
final class FinancialKpiService
{
    /**
     * @return array<string, mixed>
     */
    public function kpis(string $tenantId, ?string $asOf = null, int $months = 12): array
    {
        $asOf ??= CarbonImmutable::now()->toDateString();
        $snapshots = FinancialSnapshot::query()->where('tenant_id', $tenantId)->whereDate('snapshot_date', '<=', $asOf)
            ->orderByDesc('snapshot_date')->limit($months + 1)->get()->reverse()->values();

        $history = [];
        foreach ($snapshots as $i => $snapshot) {
            $history[] = $this->point($snapshot, $i > 0 ? $snapshots[$i - 1] : null);
        }
        $history = count($history) > $months ? array_slice($history, -$months) : $history;
        $current = $history === [] ? null : $history[count($history) - 1];
        $previous = count($history) > 1 ? $history[count($history) - 2] : null;

        return [
            'as_of' => $current['snapshot_date'] ?? null,
            'current' => $current,
            'previous' => $previous,
            'deltas' => $current !== null && $previous !== null ? $this->deltas($current, $previous) : null,
            'countback_dso' => $this->countbackDso($snapshots),
            'history' => $history,
            'formulas' => [
                'dso' => 'Average accounts receivable / credit sales in the period x days in the period',
                'dpo' => 'Average accounts payable / credit purchases in the period x days in the period',
                'ccn' => 'Inventory + accounts receivable - accounts payable',
                'inventory_turnover' => 'COGS in the period / average inventory valuation',
                'dio' => 'Average inventory valuation / COGS in the period x days in the period',
                'ccc' => 'DIO + DSO - DPO',
            ],
        ];
    }

    /**
     * Ratios of one snapshot.
     *
     * @return array<string, mixed>
     */
    public function point(FinancialSnapshot $snapshot, ?FinancialSnapshot $previous): array
    {
        $days = (int) $snapshot->snapshot_date->daysInMonth;
        $ar = Decimal::norm($snapshot->accounts_receivable_balance);
        $ap = Decimal::norm($snapshot->accounts_payable_balance);
        $inventory = Decimal::norm($snapshot->inventory_valuation_balance);
        $avg = static fn (string $now, ?string $before): string => $before === null ? $now : Decimal::div(Decimal::add($now, $before), 2);
        $avgAr = $avg($ar, $previous !== null ? Decimal::norm($previous->accounts_receivable_balance) : null);
        $avgAp = $avg($ap, $previous !== null ? Decimal::norm($previous->accounts_payable_balance) : null);
        $avgInv = $avg($inventory, $previous !== null ? Decimal::norm($previous->inventory_valuation_balance) : null);
        $creditSales = Decimal::norm($snapshot->credit_sales_period);
        $purchases = Decimal::isZero($snapshot->credit_purchases_period) ? Decimal::norm($snapshot->cogs_period) : Decimal::norm($snapshot->credit_purchases_period);
        $cogs = Decimal::norm($snapshot->cogs_period);
        $warnings = [];
        $ratio = static function (string $key, string $numerator, string $denominator, string $multiplier, array &$warnings): string {
            if (Decimal::cmp($denominator, 0) <= 0) {
                $warnings[] = $key.'_undefined';

                return '0.00';
            }

            return Decimal::round(Decimal::mul(Decimal::div($numerator, $denominator, 10), $multiplier), 2);
        };

        $dso = $ratio('dso', $avgAr, $creditSales, (string) $days, $warnings);
        $dpo = $ratio('dpo', $avgAp, $purchases, (string) $days, $warnings);
        $dio = $ratio('dio', $avgInv, $cogs, (string) $days, $warnings);
        $turnover = $ratio('inventory_turnover', $cogs, $avgInv, '1', $warnings);
        $ccn = Decimal::sub(Decimal::add($inventory, $ar), $ap);

        return [
            'snapshot_date' => $snapshot->snapshot_date->toDateString(), 'fiscal_year' => $snapshot->fiscal_year, 'fiscal_month' => $snapshot->fiscal_month,
            'days_in_period' => $days, 'accounts_receivable' => $ar, 'accounts_payable' => $ap, 'inventory' => $inventory, 'cash' => Decimal::norm($snapshot->cash_and_bank_balance),
            'overdue_receivables' => Decimal::norm($snapshot->overdue_receivables_balance),
            'overdue_receivables_percent' => Decimal::percent($snapshot->overdue_receivables_balance, $ar),
            'dso' => $dso, 'dpo' => $dpo, 'dio' => $dio, 'inventory_turnover' => $turnover,
            'ccn' => $ccn, 'ccc' => Decimal::round(Decimal::sub(Decimal::add($dio, $dso), $dpo), 2),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return array<string, string>
     */
    private function deltas(array $current, array $previous): array
    {
        $out = [];
        foreach (['dso', 'dpo', 'dio', 'inventory_turnover', 'ccn', 'ccc'] as $key) {
            $out[$key] = Decimal::sub($current[$key], $previous[$key]);
        }

        return $out;
    }

    /**
     * Countback DSO: the receivables are matched against the credit sales of the most recent months, backwards, until they
     * are used up; whole months count their days, the last one pro rata.
     *
     * @param  iterable<int, FinancialSnapshot>  $snapshots  oldest first
     */
    private function countbackDso(iterable $snapshots): ?string
    {
        $list = is_array($snapshots) ? $snapshots : iterator_to_array($snapshots, false);
        if ($list === []) {
            return null;
        }
        $remaining = Decimal::norm($list[count($list) - 1]->accounts_receivable_balance);
        $days = '0';
        for ($i = count($list) - 1; $i >= 0 && Decimal::cmp($remaining, 0) > 0; $i--) {
            $sales = Decimal::norm($list[$i]->credit_sales_period);
            $monthDays = (string) $list[$i]->snapshot_date->daysInMonth;
            if (Decimal::cmp($sales, 0) <= 0) {
                continue;
            }
            if (Decimal::cmp($remaining, $sales) >= 0) {
                $days = Decimal::add($days, $monthDays);
                $remaining = Decimal::sub($remaining, $sales);
            } else {
                $days = Decimal::add($days, Decimal::mul(Decimal::div($remaining, $sales, 8), $monthDays));
                $remaining = '0.0000';
            }
        }

        return Decimal::round($days, 2);
    }

    /**
     * Compute (and store) the snapshot of the date from the receivables, payables, stock and bank balances.
     * Stock movements are summed as signed quantity x unit cost; bank balances are the current ones.
     */
    public function buildSnapshot(string $tenantId, CarbonImmutable $date): FinancialSnapshot
    {
        $day = $date->toDateString();
        $monthStart = $date->startOfMonth()->toDateString();
        $open = static fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('date', '<=', $day);

        $ar = '0.0000';
        $overdue = '0.0000';
        $creditSales = '0.0000';
        foreach ($open(DB::table('invoices'))->whereNotNull('posted_at')->where('invoice_type', '<>', 'proforma')->whereNotIn('status', ['cancelled', 'void'])->get(['invoice_type', 'total_gross', 'total_paid', 'due_date', 'date', 'exchange_rate', 'currency']) as $invoice) {
            $rate = $invoice->exchange_rate ?: 1;
            $sign = $invoice->invoice_type === 'credit_note' ? -1 : 1;
            $balance = Decimal::mul(Decimal::mul(Decimal::sub($invoice->total_gross, $invoice->total_paid), $rate), $sign);
            $ar = Decimal::add($ar, $balance);
            if ($invoice->due_date !== null && substr((string) $invoice->due_date, 0, 10) < $day && Decimal::cmp($balance, 0) > 0) {
                $overdue = Decimal::add($overdue, $balance);
            }
            if (substr((string) $invoice->date, 0, 10) >= $monthStart) {
                $creditSales = Decimal::add($creditSales, Decimal::mul(Decimal::mul($invoice->total_gross, $rate), $sign));
            }
        }
        $ap = '0.0000';
        $purchases = '0.0000';
        foreach ($open(DB::table('passive_invoices'))->get(['document_type', 'total_amount', 'total_paid', 'exchange_rate', 'date']) as $bill) {
            $rate = $bill->exchange_rate ?: 1;
            $sign = $bill->document_type === 'TD04' ? -1 : 1;
            $ap = Decimal::add($ap, Decimal::mul(Decimal::mul(Decimal::sub($bill->total_amount, $bill->total_paid), $rate), $sign));
            if (substr((string) $bill->date, 0, 10) >= $monthStart) {
                $purchases = Decimal::add($purchases, Decimal::mul(Decimal::mul($bill->total_amount, $rate), $sign));
            }
        }
        $inventory = Decimal::norm(number_format((float) DB::table('stock_movements')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('created_at', '<=', $date->endOfDay())->selectRaw('SUM(quantity * COALESCE(unit_cost, 0)) AS v')->value('v'), 4, '.', ''));
        $cash = Decimal::norm(number_format((float) DB::table('bank_accounts')->where('tenant_id', $tenantId)->whereNull('deleted_at')->sum('current_balance'), 4, '.', ''));
        $cogs = Decimal::norm(number_format((float) DB::table('bi_sales_margin_facts')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereBetween('posting_date', [$monthStart, $day])->sum('direct_cogs_amount'), 4, '.', ''));

        return $this->store($tenantId, $date, ['accounts_receivable_balance' => $ar, 'overdue_receivables_balance' => $overdue, 'accounts_payable_balance' => $ap, 'inventory_valuation_balance' => $inventory, 'cash_and_bank_balance' => $cash, 'credit_sales_period' => $creditSales, 'credit_purchases_period' => $purchases, 'cogs_period' => $cogs]);
    }

    /**
     * Store a snapshot; the net working capital is derived.
     *
     * @param  array<string, string|int|float>  $values
     */
    public function store(string $tenantId, CarbonImmutable $date, array $values): FinancialSnapshot
    {
        $ccn = Decimal::sub(Decimal::add($values['inventory_valuation_balance'] ?? 0, $values['accounts_receivable_balance'] ?? 0), $values['accounts_payable_balance'] ?? 0);

        return FinancialSnapshot::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'snapshot_date' => $date->toDateString()],
            $values + ['fiscal_year' => $date->year, 'fiscal_month' => $date->month, 'net_working_capital' => $ccn],
        );
    }
}
