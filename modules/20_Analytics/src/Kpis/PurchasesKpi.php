<?php

declare(strict_types=1);

namespace Modules\Analytics\Kpis;

use App\Models\PassiveInvoice;
use Illuminate\Database\Eloquent\Builder;
use Modules\Analytics\Contracts\KpiCalculator;
use Modules\Analytics\Support\ConvertedAmountSql;
use Modules\Analytics\Support\ReportPeriod;

/**
 * Net purchases of the period from supplier invoices already posted to the general ledger (`gl_journal_entry_id`).
 * Credit notes (TD04) subtract. Amounts are in the accounting currency of the company.
 */
final readonly class PurchasesKpi implements KpiCalculator
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private ConvertedAmountSql $sql) {}

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'purchases';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return 'Net purchases';
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
        return 'procurement';
    }

    /**
     * {@inheritDoc}
     */
    public function calculate(ReportPeriod $period): array
    {
        $net = $this->net();
        $convertible = $this->sql->convertible('passive_invoices');
        $totals = $this->posted($period)
            ->toBase()
            ->selectRaw("COUNT(*) FILTER (WHERE {$convertible}) AS documents")
            ->selectRaw("COUNT(*) FILTER (WHERE NOT {$convertible}) AS excluded")
            ->selectRaw("COALESCE(SUM({$net} * {$this->sql->rate('passive_invoices')}) FILTER (WHERE {$convertible}), 0) AS net")
            ->first();

        $documents = (int) $totals->documents;

        return [
            'value' => $documents === 0 ? null : bcadd((string) $totals->net, '0', 2),
            'details' => [
                'currency' => $this->sql->currency(),
                'document_count' => $documents,
                'excluded_without_exchange_rate' => (int) $totals->excluded,
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function rows(ReportPeriod $period, int $limit): array
    {
        $net = $this->net().' * '.$this->sql->rate('passive_invoices');

        return $this->posted($period)
            ->toBase()
            ->join('contacts', 'contacts.id', '=', 'passive_invoices.supplier_id')
            ->whereRaw($this->sql->convertible('passive_invoices'))
            ->groupBy('passive_invoices.supplier_id', 'contacts.company_name')
            ->orderByRaw("SUM({$net}) DESC")
            ->limit($limit)
            ->selectRaw("passive_invoices.supplier_id AS supplier_id, contacts.company_name AS supplier, SUM({$net}) AS net, COUNT(*) AS documents")
            ->get()
            ->map(static fn (object $row): array => ['supplier_id' => (string) $row->supplier_id, 'supplier' => (string) $row->supplier, 'net' => bcadd((string) $row->net, '0', 2), 'documents' => (int) $row->documents])
            ->all();
    }

    /**
     * @return Builder<PassiveInvoice>
     */
    private function posted(ReportPeriod $period): Builder
    {
        return PassiveInvoice::query()
            ->whereNotNull('passive_invoices.gl_journal_entry_id')
            ->whereBetween('passive_invoices.date', [$period->from->toDateString(), $period->to->toDateString()]);
    }

    private function net(): string
    {
        return "(CASE WHEN passive_invoices.document_type = 'TD04' THEN -ABS(passive_invoices.total_net) ELSE passive_invoices.total_net END)";
    }
}
