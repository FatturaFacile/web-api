<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Infrastructure\Projection;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AnalyticsDss\Infrastructure\Support\AccountClassifier;
use Modules\AnalyticsDss\Infrastructure\Support\DssCache;
use Modules\AnalyticsDss\Infrastructure\Support\FxRateResolver;

/**
 * Incremental projector of the analytical data mart. Each `project*` method rebuilds the facts of one source document from
 * the operational tables inside a transaction (delete + insert, so a retried job never doubles a figure) and refreshes the
 * daily snapshots it touched. Only portable SQL is used, the same code runs on SQLite and PostgreSQL.
 */
final class MartProjector
{
    /** Ledger entry statuses that count: a reversed entry keeps its lines, the reversal posts the opposite ones. */
    public const array COUNTED_ENTRY_STATUSES = ['posted', 'reversed'];

    public function __construct(
        private readonly DimensionWriter $dimensions,
        private readonly FxRateResolver $fx,
        private readonly AccountClassifier $classifier,
        private readonly DssCache $cache,
    ) {}

    /**
     * Projects one ledger entry. A draft, deleted or unknown entry removes its facts.
     */
    public function projectJournalEntry(string $tenantId, string $entryId): void
    {
        $entry = DB::table('journal_entries')->where('tenant_id', $tenantId)->where('id', $entryId)->whereNull('deleted_at')->first();
        $previous = DB::table('fact_financial_transactions')->where('tenant_id', $tenantId)->where('journal_entry_id', $entryId)
            ->get(['legal_entity_id', 'date_key'])->map(static fn (object $r): string => $r->legal_entity_id.'|'.$r->date_key)->unique()->all();

        $touched = [];

        DB::transaction(function () use ($tenantId, $entryId, $entry, &$touched): void {
            DB::table('fact_financial_transactions')->where('tenant_id', $tenantId)->where('journal_entry_id', $entryId)->delete();

            if ($entry === null || ! in_array($entry->status, self::COUNTED_ENTRY_STATUSES, true)) {
                return;
            }

            $currency = $this->functionalCurrency($tenantId);
            $legalEntity = $this->dimensions->legalEntity($tenantId, $entry->company_id, $currency);
            $legalCurrency = (string) DB::table('dim_legal_entity')->where('tenant_id', $tenantId)->where('legal_entity_id', $legalEntity)->value('base_currency');
            $lines = DB::table('journal_lines as l')
                ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
                ->where('l.journal_entry_id', $entryId)->whereNull('l.deleted_at')
                ->get(['l.*', 'a.code as account_code', 'a.type as account_type', 'a.reconciliation_type']);

            $rows = [];

            foreach ($lines as $line) {
                $date = (string) ($line->posting_date ?: $entry->posting_date);
                $dateKey = $this->dimensions->dateKey($tenantId, $date);
                $category = $this->classifier->classify($line->account_code, $line->account_type, $line->reconciliation_type);
                $rate = (float) $line->exchange_rate;
                $debit = $line->functional_debit !== null ? (float) $line->functional_debit : (float) $line->debit_amount * $rate;
                $credit = $line->functional_credit !== null ? (float) $line->functional_credit : (float) $line->credit_amount * $rate;
                $functional = round($debit - $credit, 4);
                $chfRate = $this->fx->rate($tenantId, $legalCurrency, 'CHF', substr($date, 0, 10));
                $eurRate = $this->fx->rate($tenantId, $legalCurrency, 'EUR', substr($date, 0, 10));
                $partner = in_array($line->partner_type, [null, '', 'customer', 'supplier', 'contact', 'vendor'], true) ? $line->partner_id : null;
                $now = now();

                $rows[] = [
                    'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'legal_entity_id' => $legalEntity,
                    'journal_line_id' => (string) $line->id, 'journal_entry_id' => $entryId, 'date_key' => $dateKey, 'posting_date' => substr($date, 0, 10),
                    'account_id' => (string) $line->account_id, 'account_code' => $line->account_code, 'category' => $category->value,
                    'cost_center_id' => $this->dimensions->costCenter($tenantId, $line->cost_center_id),
                    'counterparty_id' => $this->dimensions->counterparty($tenantId, $partner),
                    'project_id' => $line->project_id, 'channel' => null,
                    'transaction_currency' => strtoupper((string) $line->currency),
                    'amount_transaction' => round((float) $line->debit_amount - (float) $line->credit_amount, 4),
                    'functional_currency' => $legalCurrency, 'amount_functional' => $functional,
                    'amount_chf' => $chfRate !== null ? round($functional * $chfRate, 4) : null,
                    'amount_eur' => $eurRate !== null ? round($functional * $eurRate, 4) : null,
                    'fx_rate_chf' => $chfRate, 'fx_rate_eur' => $eurRate, 'created_at' => $now, 'updated_at' => $now,
                ];
                $touched[$legalEntity.'|'.$dateKey] = true;
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('fact_financial_transactions')->insert($chunk);
            }
        });

        foreach (array_unique([...$previous, ...array_keys($touched)]) as $pair) {
            [$legalEntity, $dateKey] = explode('|', $pair);
            $this->rebuildSnapshot($tenantId, $legalEntity, (int) $dateKey);
        }

        $this->cache->flush($tenantId);
    }

    /**
     * Projects one sales invoice into the commercial velocity fact (credit notes carry negative amounts).
     */
    public function projectInvoice(string $tenantId, string $invoiceId): void
    {
        $invoice = DB::table('invoices')->where('tenant_id', $tenantId)->where('id', $invoiceId)->whereNull('deleted_at')->first();

        DB::transaction(function () use ($tenantId, $invoiceId, $invoice): void {
            DB::table('fact_commercial_velocity')->where('tenant_id', $tenantId)->where('invoice_id', $invoiceId)->delete();

            if ($invoice === null || $invoice->posted_at === null) {
                return;
            }

            $sign = str_contains(strtolower((string) $invoice->invoice_type), 'credit') ? -1.0 : 1.0;
            $date = substr((string) $invoice->date, 0, 10);
            $currency = strtoupper((string) $invoice->currency);
            $entryCompany = $invoice->gl_journal_entry_id !== null
                ? DB::table('journal_entries')->where('id', $invoice->gl_journal_entry_id)->value('company_id') : null;
            $legalEntity = $this->dimensions->legalEntity($tenantId, $entryCompany, $this->functionalCurrency($tenantId));
            $net = $sign * (float) $invoice->total_net;
            $gross = $sign * (float) $invoice->total_gross;
            $paid = $sign * (float) $invoice->total_paid;
            $open = $invoice->payment_status === 'paid' ? 0.0 : max($gross - $paid, 0.0) * ($sign < 0 ? 0.0 : 1.0);
            $paidDate = $invoice->payment_status === 'paid' ? substr((string) $invoice->updated_at, 0, 10) : null;
            $quote = $invoice->quote_id !== null ? DB::table('quotes')->where('tenant_id', $tenantId)->where('id', $invoice->quote_id)->first() : null;
            $now = now();

            DB::table('fact_commercial_velocity')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'legal_entity_id' => $legalEntity, 'invoice_id' => $invoiceId,
                'counterparty_id' => $this->dimensions->counterparty($tenantId, (string) $invoice->contact_id),
                'quote_id' => $quote?->id, 'channel' => $this->channel($invoice), 'issue_date_key' => $this->dimensions->dateKey($tenantId, $date), 'issue_date' => $date,
                'due_date' => $invoice->due_date ? substr((string) $invoice->due_date, 0, 10) : null, 'paid_date' => $paidDate,
                'currency' => $currency, 'amount_net' => $net, 'amount_gross' => $gross, 'amount_paid' => $paid, 'amount_open' => $open,
                'amount_net_chf' => $this->fx->convert($tenantId, $net, $currency, 'CHF', $date),
                'amount_net_eur' => $this->fx->convert($tenantId, $net, $currency, 'EUR', $date),
                'amount_open_chf' => $this->fx->convert($tenantId, $open, $currency, 'CHF', $date),
                'amount_open_eur' => $this->fx->convert($tenantId, $open, $currency, 'EUR', $date),
                'discount_amount' => $sign * (float) $invoice->discount_amount,
                'days_to_pay' => $paidDate !== null ? (int) round((strtotime($paidDate) - strtotime($date)) / 86400) : null,
                'quote_to_cash_days' => $quote !== null && $paidDate !== null ? (int) round((strtotime($paidDate) - strtotime((string) $quote->date)) / 86400) : null,
                'payment_status' => (string) $invoice->payment_status, 'created_at' => $now, 'updated_at' => $now,
            ]);
        });

        $this->cache->flush($tenantId);
    }

    /**
     * Projects an approved project timesheet as billable or non-billable hours.
     */
    public function projectTimesheet(string $tenantId, string $timesheetId): void
    {
        $sheet = DB::table('project_timesheets')->where('tenant_id', $tenantId)->where('id', $timesheetId)->whereNull('deleted_at')->first();

        DB::transaction(function () use ($tenantId, $timesheetId, $sheet): void {
            DB::table('fact_operational_efficiency')->where('tenant_id', $tenantId)->where('source_type', 'timesheet')->where('source_id', $timesheetId)->delete();

            if ($sheet === null || $sheet->status !== 'approved') {
                return;
            }

            $hours = (float) $sheet->hours_worked + (float) $sheet->overtime_hours;
            $billable = (bool) $sheet->is_billable;
            $now = now();

            DB::table('fact_operational_efficiency')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
                'legal_entity_id' => $this->dimensions->legalEntity($tenantId, $sheet->company_id, $this->functionalCurrency($tenantId)),
                'source_type' => 'timesheet', 'source_id' => $timesheetId, 'date_key' => $this->dimensions->dateKey($tenantId, (string) $sheet->work_date),
                'work_date' => substr((string) $sheet->work_date, 0, 10), 'project_id' => $sheet->project_id, 'user_id' => $sheet->user_id,
                'billable_hours' => $billable ? $hours : 0.0, 'non_billable_hours' => $billable ? 0.0 : $hours,
                'labour_cost' => (float) $sheet->total_cost, 'labour_charge' => $billable ? (float) $sheet->total_charge : 0.0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        });

        $this->cache->flush($tenantId);
    }

    /**
     * Projects a stock movement: outbound movements feed inventory turnover and the unit cost variance against the standard.
     */
    public function projectStockMovement(string $tenantId, string $movementId): void
    {
        $movement = DB::table('stock_movements')->where('tenant_id', $tenantId)->where('id', $movementId)->whereNull('deleted_at')->first();

        DB::transaction(function () use ($tenantId, $movementId, $movement): void {
            DB::table('fact_operational_efficiency')->where('tenant_id', $tenantId)->where('source_type', 'stock_movement')->where('source_id', $movementId)->delete();

            if ($movement === null) {
                return;
            }

            $type = strtolower((string) $movement->movement_type.' '.(string) $movement->type);
            $outbound = $movement->type === 'out' || (float) $movement->quantity < 0.0 || (bool) preg_match('/issue|sale|consum|deliver|dispatch/', $type);

            if (! $outbound) {
                return;
            }

            $quantity = abs((float) $movement->quantity);
            $value = abs((float) $movement->total_valuation) ?: $quantity * (float) $movement->unit_cost;
            $unitCost = $quantity > 0.0 ? $value / $quantity : null;
            $standard = $movement->product_id !== null ? DB::table('products')->where('tenant_id', $tenantId)->where('id', $movement->product_id)->value('cost_price') : null;
            $date = substr((string) $movement->executed_at, 0, 10);
            $now = now();

            DB::table('fact_operational_efficiency')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
                'legal_entity_id' => $this->dimensions->legalEntity($tenantId, $movement->company_id, $this->functionalCurrency($tenantId)),
                'source_type' => 'stock_movement', 'source_id' => $movementId, 'date_key' => $this->dimensions->dateKey($tenantId, $date), 'work_date' => $date,
                'product_id' => $this->dimensions->product($tenantId, $movement->product_id), 'quantity_out' => $quantity, 'value_out' => round($value, 4),
                'unit_cost' => $unitCost !== null ? round($unitCost, 6) : null, 'standard_unit_cost' => $standard !== null ? (float) $standard : null,
                'unit_cost_variance' => $unitCost !== null && $standard !== null ? round($unitCost - (float) $standard, 6) : null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        });

        $this->cache->flush($tenantId);
    }

    /**
     * ABC/XYZ classes of the product dimension, refreshed after stock movements.
     */
    public function classifyProducts(string $tenantId): void
    {
        $this->dimensions->classifyProducts($tenantId);
    }

    /**
     * Recomputes the pre-aggregated snapshot of one legal entity and day from its facts.
     */
    public function rebuildSnapshot(string $tenantId, string $legalEntityId, int $dateKey): void
    {
        $groups = DB::table('fact_financial_transactions')
            ->where('tenant_id', $tenantId)->where('legal_entity_id', $legalEntityId)->where('date_key', $dateKey)->whereNull('deleted_at')
            ->selectRaw('category, SUM(COALESCE(amount_chf, 0)) as chf, SUM(COALESCE(amount_eur, 0)) as eur, SUM(amount_functional) as functional, COUNT(*) as lines')
            ->groupBy('category')->get();

        $scope = DB::table('agg_executive_daily_snapshots')->where('tenant_id', $tenantId)->where('legal_entity_id', $legalEntityId)->where('date_key', $dateKey);

        if ($groups->isEmpty()) {
            $scope->delete();

            return;
        }

        // Ledger sign is debit minus credit: revenue and payables are naturally credit balances, so they flip.
        $natural = [
            'revenue' => ['revenue', -1], 'cogs' => ['cogs', 1], 'opex' => ['opex', 1], 'depreciation' => ['depreciation', 1], 'cash' => ['cash_movement', 1],
            'receivable' => ['receivable_movement', 1], 'payable' => ['payable_movement', -1], 'inventory' => ['inventory_movement', 1],
        ];
        $values = ['functional_checksum' => 0.0, 'transaction_count' => 0];

        foreach ($natural as [$column]) {
            $values[$column.'_chf'] = 0.0;
            $values[$column.'_eur'] = 0.0;
        }

        foreach ($groups as $group) {
            $values['functional_checksum'] += (float) $group->functional;
            $values['transaction_count'] += (int) $group->lines;

            if (isset($natural[$group->category])) {
                [$column, $sign] = $natural[$group->category];
                $values[$column.'_chf'] += $sign * (float) $group->chf;
                $values[$column.'_eur'] += $sign * (float) $group->eur;
            }
        }

        $values = array_map(static fn (float|int $v): float|int => is_float($v) ? round($v, 4) : $v, $values);
        $date = substr((string) DB::table('dim_date')->where('tenant_id', $tenantId)->where('date_key', $dateKey)->value('date'), 0, 10);

        if ($scope->exists()) {
            $scope->update([...$values, 'updated_at' => now()]);

            return;
        }

        DB::table('agg_executive_daily_snapshots')->insert([
            ...$values, 'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'legal_entity_id' => $legalEntityId, 'date_key' => $dateKey,
            'snapshot_date' => $date, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Rebuilds the whole mart of a tenant from the operational tables. Returns the number of source documents projected.
     */
    public function rebuildTenant(string $tenantId): int
    {
        $count = 0;

        // Facts of sources that are no longer posted would survive a per-document pass, so the derived tables start empty.
        foreach (['agg_executive_daily_snapshots', 'fact_financial_transactions', 'fact_commercial_velocity', 'fact_operational_efficiency'] as $table) {
            DB::table($table)->where('tenant_id', $tenantId)->delete();
        }

        $sources = [
            ['journal_entries', fn (string $id) => $this->projectJournalEntry($tenantId, $id), static fn ($q) => $q->whereIn('status', self::COUNTED_ENTRY_STATUSES)],
            ['invoices', fn (string $id) => $this->projectInvoice($tenantId, $id), static fn ($q) => $q->whereNotNull('posted_at')],
            ['project_timesheets', fn (string $id) => $this->projectTimesheet($tenantId, $id), static fn ($q) => $q->where('status', 'approved')],
            ['stock_movements', fn (string $id) => $this->projectStockMovement($tenantId, $id), static fn ($q) => $q],
        ];

        foreach ($sources as [$table, $project, $filter]) {
            $filter(DB::table($table)->where('tenant_id', $tenantId)->whereNull('deleted_at'))->orderBy('id')->pluck('id')->each(function (string $id) use ($project, &$count): void {
                $project($id);
                $count++;
            });
        }

        $this->dimensions->classifyProducts($tenantId);

        return $count;
    }

    /**
     * Sales channel of an invoice, from the way it originated.
     */
    private function channel(object $invoice): string
    {
        return match (true) {
            $invoice->subscription_id !== null => 'subscription',
            $invoice->project_id !== null => 'project',
            $invoice->sales_order_id !== null => 'order',
            default => 'direct',
        };
    }

    private function functionalCurrency(string $tenantId): string
    {
        return strtoupper((string) (DB::table('tenants')->where('id', $tenantId)->value('default_currency') ?: 'EUR'));
    }
}
