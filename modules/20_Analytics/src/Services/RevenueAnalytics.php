<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;

/**
 * Period comparisons on issued and received documents, net of VAT, in the accounting currency of the company. Proforma
 * documents never count, nor do cancelled or void ones and drafts still carrying their placeholder number; credit notes
 * subtract, whatever sign their totals were stored with. A document in another currency is converted with the exchange
 * rate stored on it, as the ledger does (amount times rate); a received one without a rate is left out.
 */
final class RevenueAnalytics
{
    private const array VoidStatuses = ['cancelled', 'void'];

    /** Drafts of the invoicing module carry this placeholder until emission; invoices of the older flows stay in draft with their real number. */
    private const string DraftNumberPrefix = 'DRAFT-';

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @return array{year: int, previous_year: int, months: list<array{month: int, current: string, previous: string, change_percent: ?string}>, totals: array{current: string, previous: string, change_percent: ?string}}
     */
    public function yearOverYear(int $year): array
    {
        $byMonth = fn (int $target): Collection => $this->issued($target)
            ->selectRaw('EXTRACT(MONTH FROM invoices.date)::int AS period, SUM('.$this->signedNet().') AS amount')
            ->groupBy('period')
            ->pluck('amount', 'period');

        $current = $byMonth($year);
        $previous = $byMonth($year - 1);
        $months = [];

        foreach (range(1, 12) as $month) {
            $months[] = ['month' => $month, ...$this->compared((string) ($current[$month] ?? '0'), (string) ($previous[$month] ?? '0'))];
        }

        return [
            'year' => $year,
            'previous_year' => $year - 1,
            'months' => $months,
            'totals' => $this->compared($this->sum($current), $this->sum($previous)),
        ];
    }

    /**
     * @return array{dimension: string, year: int, previous_year: int, rows: list<array{key: ?string, label: string, current: string, previous: string, change_percent: ?string}>}
     */
    public function breakdown(string $dimension, int $year): array
    {
        $rows = match ($dimension) {
            'customer-type' => [$this->customerTypes($year), $this->customerTypes($year - 1)],
            'product' => [$this->products($year), $this->products($year - 1)],
            'purchases' => [$this->suppliers($year), $this->suppliers($year - 1)],
        };

        [$current, $previous] = $rows;
        $merged = [];

        foreach ($current->keys()->merge($previous->keys())->unique() as $key) {
            $merged[] = [
                'key' => $key === '' ? null : (string) $key,
                'label' => (string) ($current[$key]->label ?? $previous[$key]->label ?? ''),
                ...$this->compared((string) ($current[$key]->amount ?? '0'), (string) ($previous[$key]->amount ?? '0')),
            ];
        }

        usort($merged, static fn (array $left, array $right): int => bccomp($right['current'], $left['current'], 2));

        return ['dimension' => $dimension, 'year' => $year, 'previous_year' => $year - 1, 'rows' => $merged];
    }

    /**
     * A contact with a VAT number is a business, any other a private customer.
     *
     * @return Collection<string, object>
     */
    private function customerTypes(int $year): Collection
    {
        $type = "CASE WHEN contacts.vat_number IS NOT NULL AND contacts.vat_number <> '' THEN 'business' ELSE 'private' END";

        return $this->issued($year)
            ->join('contacts', 'contacts.id', '=', 'invoices.contact_id')
            ->selectRaw("{$type} AS group_key, {$type} AS label, SUM(".$this->signedNet().') AS amount')
            ->groupByRaw('1, 2')
            ->toBase()
            ->get()
            ->keyBy('group_key');
    }

    /**
     * @return Collection<string, object>
     */
    private function products(int $year): Collection
    {
        $amount = "(CASE WHEN invoices.invoice_type = 'credit_note' THEN -ABS(invoice_items.line_total) ELSE invoice_items.line_total END) * {$this->rate('invoices')}";

        return $this->issued($year)
            ->join('invoice_items', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->selectRaw("COALESCE(invoice_items.product_id::text, invoice_items.description) AS group_key, MIN(invoice_items.description) AS label, SUM({$amount}) AS amount")
            ->groupBy('group_key')
            ->toBase()
            ->get()
            ->keyBy('group_key');
    }

    /**
     * @return Collection<string, object>
     */
    private function suppliers(int $year): Collection
    {
        $net = "(CASE WHEN passive_invoices.document_type = 'TD04' THEN -ABS(passive_invoices.total_net) ELSE passive_invoices.total_net END) * {$this->rate('passive_invoices')}";

        return PassiveInvoice::query()
            ->join('contacts', 'contacts.id', '=', 'passive_invoices.supplier_id')
            ->whereYear('passive_invoices.date', $year)
            ->where(fn (Builder $query) => $query->where('passive_invoices.currency', $this->accountingCurrency())->orWhereNotNull('passive_invoices.exchange_rate'))
            ->selectRaw("passive_invoices.supplier_id::text AS group_key, MIN(contacts.company_name) AS label, SUM({$net}) AS amount")
            ->groupBy('group_key')
            ->toBase()
            ->get()
            ->keyBy('group_key');
    }

    private function issued(int $year): Builder
    {
        return Invoice::query()
            ->whereYear('invoices.date', $year)
            ->where('invoices.invoice_type', '!=', 'proforma')
            ->whereNotIn('invoices.status', self::VoidStatuses)
            ->where(static fn (Builder $query) => $query->where('invoices.status', '!=', 'draft')->orWhere('invoices.invoice_number', 'not like', self::DraftNumberPrefix.'%'));
    }

    private function signedNet(): string
    {
        return "(CASE WHEN invoices.invoice_type = 'credit_note' THEN -ABS(invoices.total_net) ELSE invoices.total_net END) * {$this->rate('invoices')}";
    }

    /**
     * The factor to the accounting currency of a row of the table. The currency code is quoted by PDO, never interpolated raw.
     */
    private function rate(string $table): string
    {
        $currency = DB::getPdo()->quote($this->accountingCurrency());

        return "(CASE WHEN UPPER({$table}.currency) = {$currency} THEN 1 ELSE COALESCE(NULLIF({$table}.exchange_rate, 0), 1) END)";
    }

    private function accountingCurrency(): string
    {
        return TenantAccountingCurrency::of((string) $this->tenantContext->getTenantId());
    }

    /**
     * @param  Collection<int|string, mixed>  $amounts
     */
    private function sum(Collection $amounts): string
    {
        return $amounts->reduce(static fn (string $total, mixed $amount): string => bcadd($total, (string) $amount, 2), '0.00');
    }

    /**
     * @return array{current: string, previous: string, change_percent: ?string}
     */
    private function compared(string $current, string $previous): array
    {
        $current = bcadd($current, '0', 2);
        $previous = bcadd($previous, '0', 2);

        return [
            'current' => $current,
            'previous' => $previous,
            'change_percent' => bccomp($previous, '0', 2) === 0 ? null : bcround(bcdiv(bcmul(bcsub($current, $previous, 2), '100', 2), $previous, 4), 2),
        ];
    }
}
