<?php

declare(strict_types=1);

namespace Modules\Assistant\Catalogue;

use App\Models\Invoice;
use Modules\Assistant\Contracts\CatalogueQuery;

final class OpenReceivablesQuery implements CatalogueQuery
{
    public function key(): string
    {
        return 'open_receivables';
    }

    public function module(): string
    {
        return 'invoicing';
    }

    public function description(): string
    {
        return 'Total still due from customers, by currency';
    }

    public function keywords(): array
    {
        return ['crediti aperti', 'crediti verso clienti', 'ci devono i clienti', 'da incassare', 'open receivables', 'customers owe', 'offene forderungen', 'créances ouvertes'];
    }

    public function parameters(): array
    {
        return [];
    }

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
