<?php

declare(strict_types=1);

namespace Modules\Assistant\Catalogue;

use App\Models\PassiveInvoice;
use Modules\Assistant\Contracts\CatalogueQuery;

/**
 * Catalogue query: open payables.
 */
final class OpenPayablesQuery implements CatalogueQuery
{
    /**
     * Stable key of the query.
     */
    public function key(): string
    {
        return 'open_payables';
    }

    /**
     * Module key whose read access gates the query.
     */
    public function module(): string
    {
        return 'treasury';
    }

    /**
     * Human description of the query.
     */
    public function description(): string
    {
        return 'Total still due to suppliers, by currency';
    }

    /**
     * Phrases that select this query.
     */
    public function keywords(): array
    {
        return ['debiti verso fornitori', 'dobbiamo ai fornitori', 'da pagare', 'fatture fornitori aperte', 'open payables', 'owe suppliers', 'offene verbindlichkeiten', 'dettes fournisseurs'];
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
        return PassiveInvoice::query()
            ->where('tenant_id', $tenantId)
            ->where('payment_status', '!=', 'paid')
            ->where('document_type', '!=', 'TD04')
            ->selectRaw('currency, COUNT(*) as invoices, SUM(total_amount - total_paid) as open_amount')
            ->groupBy('currency')
            ->orderBy('currency')
            ->toBase()
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }
}
