<?php

declare(strict_types=1);

namespace Modules\Analytics\Kpis;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Modules\Analytics\Contracts\KpiCalculator;
use Modules\Analytics\Support\ConvertedAmountSql;
use Modules\Analytics\Support\ReportPeriod;

/**
 * Net sales of the period from posted invoices. An invoice counts only after posting (`posted_at`); proforma, cancelled
 * and void documents never count, credit notes subtract whatever sign their totals were stored with. Amounts are in the
 * accounting currency of the company.
 */
final readonly class SalesKpi implements KpiCalculator
{
    private const array VoidStatuses = ['cancelled', 'void'];

    /**
     * Inject the collaborators.
     */
    public function __construct(private ConvertedAmountSql $sql) {}

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'sales';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return 'Net sales';
    }

    /**
     * {@inheritDoc}
     */
    public function unit(): string
    {
        return 'currency';
    }

    /**
     * {@inheritDoc}
     */
    public function kind(): string
    {
        return 'period';
    }

    /**
     * {@inheritDoc}
     */
    public function requiredModule(): string
    {
        return 'invoicing';
    }

    /**
     * {@inheritDoc}
     */
    public function calculate(ReportPeriod $period): array
    {
        $sign = $this->sign();
        $rate = $this->sql->rate('invoices');
        $totals = $this->posted($period)
            ->toBase()
            ->selectRaw("COUNT(*) FILTER (WHERE {$this->sql->convertible('invoices')}) AS documents")
            ->selectRaw("COUNT(*) FILTER (WHERE NOT {$this->sql->convertible('invoices')}) AS excluded")
            ->selectRaw("COALESCE(SUM({$sign('total_net')} * {$rate}) FILTER (WHERE {$this->sql->convertible('invoices')}), 0) AS net")
            ->selectRaw("COALESCE(SUM({$sign('total_tax')} * {$rate}) FILTER (WHERE {$this->sql->convertible('invoices')}), 0) AS tax")
            ->first();

        $documents = (int) $totals->documents;

        return [
            'value' => $documents === 0 ? null : bcadd((string) $totals->net, '0', 2),
            'details' => [
                'currency' => $this->sql->currency(),
                'document_count' => $documents,
                'tax' => $documents === 0 ? null : bcadd((string) $totals->tax, '0', 2),
                'excluded_without_exchange_rate' => (int) $totals->excluded,
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function rows(ReportPeriod $period, int $limit): array
    {
        $sign = $this->sign();
        $name = "COALESCE(NULLIF(contacts.company_name, ''), TRIM(COALESCE(contacts.first_name, '') || ' ' || COALESCE(contacts.last_name, '')))";

        return $this->posted($period)
            ->toBase()
            ->join('contacts', 'contacts.id', '=', 'invoices.contact_id')
            ->whereRaw($this->sql->convertible('invoices'))
            ->groupBy('invoices.contact_id')
            ->groupByRaw($name)
            ->orderByRaw('SUM('.$sign('total_net').' * '.$this->sql->rate('invoices').') DESC')
            ->limit($limit)
            ->selectRaw("invoices.contact_id AS customer_id, {$name} AS customer, SUM({$sign('total_net')} * {$this->sql->rate('invoices')}) AS net, COUNT(*) AS documents")
            ->get()
            ->map(static fn (object $row): array => ['customer_id' => (string) $row->customer_id, 'customer' => (string) $row->customer, 'net' => bcadd((string) $row->net, '0', 2), 'documents' => (int) $row->documents])
            ->all();
    }

    /**
     * @return Builder<Invoice>
     */
    private function posted(ReportPeriod $period): Builder
    {
        return Invoice::query()
            ->whereNotNull('invoices.posted_at')
            ->whereBetween('invoices.date', [$period->from->toDateString(), $period->to->toDateString()])
            ->where('invoices.invoice_type', '!=', 'proforma')
            ->whereNotIn('invoices.status', self::VoidStatuses);
    }

    /**
     * @return callable(string): string
     */
    private function sign(): callable
    {
        return static fn (string $column): string => "(CASE WHEN invoices.invoice_type = 'credit_note' THEN -ABS(invoices.{$column}) ELSE invoices.{$column} END)";
    }
}
