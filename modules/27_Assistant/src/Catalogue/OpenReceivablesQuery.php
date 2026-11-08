<?php

declare(strict_types=1);

namespace Modules\Assistant\Catalogue;

use App\Models\Invoice;
use Modules\Assistant\Contracts\CatalogueQuery;

/**
 * Catalogue query: open receivables.
 */
final class OpenReceivablesQuery implements CatalogueQuery
{
    /**
     * Stable key of the query.
     */
    public function key(): string
    {
        return 'open_receivables';
    }

    /**
     * Module key whose read access gates the query.
     */
    public function module(): string
    {
        return 'invoicing';
    }

    /**
     * Human description of the query.
     */
    public function description(): string
    {
        return 'Total still due from customers, by currency';
    }

    /**
     * Phrases that select this query.
     */
    public function keywords(): array
    {
        return ['crediti aperti', 'crediti verso clienti', 'ci devono i clienti', 'da incassare', 'open receivables', 'customers owe', 'offene forderungen', 'créances ouvertes'];
    }

    /**
     * Declared parameters with their bounds.
     */
    public function parameters(): array
    {
        return [];
    }

    /**
     * Run the query for the tenant.
     */
    public function run(string $tenantId, array $parameters): array
    {
        return Invoice::query()
            ->where('tenant_id', $tenantId)
            ->where('payment_status', '!=', 'paid')
            ->whereNotIn('invoice_type', ['proforma', 'credit_note'])
            ->selectRaw('currency, COUNT(*) as invoices, SUM(total_gross - total_paid) as open_amount')
            ->groupBy('currency')
            ->orderBy('currency')
            ->toBase()
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }
}
