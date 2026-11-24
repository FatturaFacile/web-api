<?php

declare(strict_types=1);

namespace Modules\Analytics\Kpis;

use App\Models\PassiveInvoice;
use Illuminate\Database\Query\Builder;

/**
 * Payables aging: supplier invoices posted to the ledger and not yet fully paid. Credit notes (TD04) are not payables.
 */
final readonly class PayablesAgingKpi extends AgingKpi
{
    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'payables-aging';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return 'Payables aging';
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
    protected function openItems(): Builder
    {
        return PassiveInvoice::query()
            ->toBase()
            ->join('contacts', 'contacts.id', '=', 'passive_invoices.supplier_id')
            ->whereNotNull('passive_invoices.gl_journal_entry_id')
            ->where('passive_invoices.document_type', '!=', 'TD04')
            ->where('passive_invoices.payment_status', '!=', 'paid')
            ->whereRaw('(passive_invoices.total_amount - COALESCE(passive_invoices.total_paid, 0)) > 0');
    }

    /**
     * {@inheritDoc}
     */
    protected function table(): string
    {
        return 'passive_invoices';
    }

    /**
     * {@inheritDoc}
     */
    protected function outstandingSql(): string
    {
        return '(passive_invoices.total_amount - COALESCE(passive_invoices.total_paid, 0))';
    }

    /**
     * {@inheritDoc}
     */
    protected function dueSql(): string
    {
        return 'COALESCE(passive_invoices.due_date, passive_invoices.date)';
    }

    /**
     * {@inheritDoc}
     */
    protected function counterpartyColumn(): string
    {
        return 'supplier_id';
    }
}
