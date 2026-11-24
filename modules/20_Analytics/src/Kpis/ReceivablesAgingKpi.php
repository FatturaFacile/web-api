<?php

declare(strict_types=1);

namespace Modules\Analytics\Kpis;

use App\Models\Invoice;
use Illuminate\Database\Query\Builder;

/**
 * Receivables aging: posted sales invoices not yet fully paid. Proforma, credit notes, cancelled and void documents are
 * not receivables.
 */
final readonly class ReceivablesAgingKpi extends AgingKpi
{
    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'receivables-aging';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return 'Receivables aging';
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
    protected function openItems(): Builder
    {
        return Invoice::query()
            ->toBase()
            ->join('contacts', 'contacts.id', '=', 'invoices.contact_id')
            ->whereNotNull('invoices.posted_at')
            ->whereNotIn('invoices.invoice_type', ['proforma', 'credit_note'])
            ->whereNotIn('invoices.status', ['cancelled', 'void'])
            ->where('invoices.payment_status', '!=', 'paid')
            ->whereRaw('(invoices.total_gross - COALESCE(invoices.total_paid, 0)) > 0');
    }

    /**
     * {@inheritDoc}
     */
    protected function table(): string
    {
        return 'invoices';
    }

    /**
     * {@inheritDoc}
     */
    protected function outstandingSql(): string
    {
        return '(invoices.total_gross - COALESCE(invoices.total_paid, 0))';
    }

    /**
     * {@inheritDoc}
     */
    protected function dueSql(): string
    {
        return 'COALESCE(invoices.due_date, invoices.date)';
    }

    /**
     * {@inheritDoc}
     */
    protected function counterpartyColumn(): string
    {
        return 'contact_id';
    }
}
