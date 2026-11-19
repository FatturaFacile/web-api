<?php

declare(strict_types=1);

namespace Modules\Controlling\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Controlling\Support\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;

/**
 * Keeps the margin fact table (bi_sales_margin_facts) in line with the sources: it rebuilds the fact rows from the
 * posted sales invoices and credit notes, and it spreads the overhead the allocation runs have put on cost centers and
 * jobs over the fact rows of the same center or job, in proportion to net revenue.
 *
 * Every monetary column is signed: a credit note row carries negative revenue, cost and margin, so that plain sums are the
 * net figures. Per row, net = gross - discount, first = net - cogs, second = first - commission - logistics and
 * full = second - allocated overhead.
 */
final class MarginFactSynchronizer
{
    /**
     * Rebuild the facts of the sales documents posted in the range (all of them without a range).
     *
     * @return int number of fact rows written
     */
    public function rebuild(string $tenantId, ?string $from = null, ?string $to = null): int
    {
        $currency = TenantAccountingCurrency::of($tenantId);
        $invoices = DB::table('invoices')
            ->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereNotNull('posted_at')
            ->where('invoice_type', '<>', 'proforma')->whereNotIn('status', ['cancelled', 'void'])
            ->when($from !== null, static fn ($q) => $q->where('date', '>=', $from))
            ->when($to !== null, static fn ($q) => $q->where('date', '<=', $to))
            ->get();

        DB::table('bi_sales_margin_facts')->where('tenant_id', $tenantId)->whereNotNull('source_id')
            ->when($from !== null, static fn ($q) => $q->where('posting_date', '>=', $from))
            ->when($to !== null, static fn ($q) => $q->where('posting_date', '<=', $to))
            ->delete();

        $written = 0;
        $months = [];
        $now = now();
        foreach ($invoices as $invoice) {
            $rate = strtoupper((string) $invoice->currency) === strtoupper($currency) ? '1' : (string) ($invoice->exchange_rate ?: 0);
            if (Decimal::isZero($rate)) {
                continue;
            }
            $isCredit = $invoice->invoice_type === 'credit_note';
            $sign = $isCredit ? '-1' : '1';
            $contact = DB::table('contacts')->where('id', $invoice->contact_id)->first();
            $commissionRate = $invoice->sales_rep_id ? (string) (DB::table('sales_representatives')->where('id', $invoice->sales_rep_id)->value('default_commission_rate') ?? 0) : '0';
            $jobId = $invoice->sales_order_id ? DB::table('analytical_jobs')->where('tenant_id', $tenantId)->where('sales_order_id', $invoice->sales_order_id)->whereNull('deleted_at')->value('id') : null;
            $date = substr((string) $invoice->date, 0, 10);
            $rows = [];
            foreach (DB::table('invoice_items')->where('invoice_id', $invoice->id)->whereNull('deleted_at')->get() as $item) {
                $product = $item->product_id ? DB::table('products')->where('id', $item->product_id)->first() : null;
                $gross = Decimal::mul(Decimal::mul($item->quantity, $item->unit_price), $rate);
                $discount = Decimal::mul($gross, Decimal::div($item->discount_percentage, 100, 8));
                $net = Decimal::sub($gross, $discount);
                $cogs = Decimal::mul(Decimal::mul($item->quantity, $product->cost_price ?? 0), $rate);
                $commission = Decimal::mul($net, Decimal::div($commissionRate, 100, 8));
                $rows[] = $this->row($tenantId, $date, [
                    'document_type' => $isCredit ? 'credit_note' : 'invoice', 'source_id' => $invoice->id,
                    'product_id' => $item->product_id, 'product_family_id' => $product->category_id ?? null,
                    'customer_id' => $invoice->contact_id, 'country_code' => $contact->country ?? null,
                    'sales_agent_id' => $invoice->sales_rep_id, 'job_id' => $jobId,
                    'quantity_sold' => Decimal::mul($item->quantity, $sign),
                    'gross_revenue' => Decimal::mul($gross, $sign), 'discount_amount' => Decimal::mul($discount, $sign),
                    'direct_cogs_amount' => Decimal::mul($cogs, $sign), 'commission_amount' => Decimal::mul($commission, $sign),
                ], $now);
            }
            $this->insert($rows);
            $written += count($rows);
            $months[substr($date, 0, 7)] = [(int) substr($date, 0, 4), (int) substr($date, 5, 2)];
        }
        foreach ($months as [$year, $month]) {
            $this->syncOverheads($tenantId, $year, $month);
        }

        return $written;
    }

    /**
     * Build a complete fact row; the margins are derived from the amounts.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function row(string $tenantId, string $date, array $values, mixed $now = null): array
    {
        $now ??= now();
        $gross = Decimal::norm($values['gross_revenue'] ?? 0);
        $discount = Decimal::norm($values['discount_amount'] ?? 0);
        $net = Decimal::sub($gross, $discount);
        $first = Decimal::sub($net, $values['direct_cogs_amount'] ?? 0);
        $second = Decimal::sub(Decimal::sub($first, $values['commission_amount'] ?? 0), $values['logistics_cost_amount'] ?? 0);
        $overhead = Decimal::norm($values['allocated_overhead_amount'] ?? 0);

        return $values + [
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'posting_date' => $date,
            'fiscal_year' => (int) substr($date, 0, 4), 'fiscal_month' => (int) substr($date, 5, 2),
            'document_type' => 'invoice', 'net_revenue' => $net,
            'first_contribution_margin' => $first, 'second_contribution_margin' => $second,
            'full_industrial_margin' => Decimal::sub($second, $overhead),
            'created_at' => $now, 'updated_at' => $now,
        ] + ['allocated_overhead_amount' => $overhead];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function insert(array $rows): void
    {
        $columns = $this->columns();
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('bi_sales_margin_facts')->insert(array_map(static function (array $row) use ($columns): array {
                $merged = array_merge($columns, $row);
                ksort($merged);

                return $merged;
            }, $chunk));
        }
    }

    /**
     * Spread the net overhead of the allocation entries of the month over the fact rows of the same center or job, in
     * proportion to net revenue (penny residual on the largest row), and refresh the full industrial margin.
     */
    public function syncOverheads(string $tenantId, int $year, int $month): void
    {
        DB::transaction(function () use ($tenantId, $year, $month): void {
            $facts = DB::table('bi_sales_margin_facts')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('fiscal_year', $year)->where('fiscal_month', $month)->get();
            if ($facts->isEmpty()) {
                return;
            }
            $overhead = $facts->mapWithKeys(static fn (object $f): array => [$f->id => '0.0000'])->all();
            $entries = DB::table('analytical_ledger_entries')->where('tenant_id', $tenantId)->whereNull('deleted_at')
                ->where('fiscal_year', $year)->where('fiscal_month', $month)->where('source_type', 'overhead_allocation')
                ->selectRaw('cost_center_id, job_id, SUM(amount) AS net')->groupBy('cost_center_id', 'job_id')->get();
            foreach ($entries as $entry) {
                $net = Decimal::norm(number_format((float) $entry->net, 4, '.', ''));
                if (Decimal::cmp($net, 0) <= 0) {
                    continue;
                }
                $targets = $facts->filter(static fn (object $f): bool => $entry->job_id !== null ? $f->job_id === $entry->job_id : ($entry->cost_center_id !== null && $f->cost_center_id === $entry->cost_center_id));
                if ($targets->isEmpty()) {
                    continue;
                }
                $weights = $targets->mapWithKeys(static fn (object $f): array => [$f->id => Decimal::abs((string) $f->net_revenue)])->all();
                if (Decimal::isZero(array_sum(array_map(static fn (string $w): float => (float) $w, $weights)))) {
                    $weights = array_map(static fn (string $w): string => '1', $weights);
                }
                foreach (Decimal::spread($net, $weights) as $id => $share) {
                    $overhead[$id] = Decimal::add($overhead[$id], $share);
                }
            }
            foreach ($facts as $fact) {
                $second = Decimal::norm((string) $fact->second_contribution_margin);
                DB::table('bi_sales_margin_facts')->where('id', $fact->id)->update([
                    'allocated_overhead_amount' => $overhead[$fact->id], 'full_industrial_margin' => Decimal::sub($second, $overhead[$fact->id]), 'updated_at' => now(),
                ]);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function columns(): array
    {
        $defaults = ['source_id' => null, 'product_id' => null, 'product_family_id' => null, 'customer_id' => null, 'country_code' => null, 'region_code' => null, 'province_code' => null, 'sales_agent_id' => null, 'production_department_id' => null, 'cost_center_id' => null, 'job_id' => null];
        foreach (['quantity_sold', 'gross_revenue', 'discount_amount', 'net_revenue', 'direct_cogs_amount', 'allocated_overhead_amount', 'commission_amount', 'logistics_cost_amount', 'first_contribution_margin', 'second_contribution_margin', 'full_industrial_margin'] as $column) {
            $defaults[$column] = '0.0000';
        }

        return $defaults;
    }
}
