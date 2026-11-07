<?php

declare(strict_types=1);

namespace Modules\Assistant\Catalogue;

use App\Models\Invoice;
use Modules\Assistant\Contracts\CatalogueQuery;

final class TopCustomersQuery implements CatalogueQuery
{
    public function key(): string
    {
        return 'top_customers';
    }

    public function module(): string
    {
        return 'invoicing';
    }

    public function description(): string
    {
        return 'Customers with the highest invoiced amount in a year';
    }

    public function keywords(): array
    {
        return ['migliori clienti', 'clienti principali', 'top clienti', 'top customers', 'best customers', 'beste kunden', 'meilleurs clients'];
    }

    public function parameters(): array
    {
        return [
            'year' => ['min' => 2000, 'max' => 2100, 'default' => (int) now()->year],
            'limit' => ['min' => 1, 'max' => 50, 'default' => 10],
        ];
    }

    public function run(string $tenantId, array $parameters): array
    {
        return Invoice::query()
            ->where('invoices.tenant_id', $tenantId)
            ->whereNotIn('invoices.invoice_type', ['proforma', 'credit_note'])
            ->whereYear('invoices.date', $parameters['year'])
            ->join('contacts', 'contacts.id', '=', 'invoices.contact_id')
            ->where('contacts.tenant_id', $tenantId)
            ->selectRaw('contacts.company_name as customer, invoices.currency, SUM(invoices.total_gross) as invoiced')
            ->groupBy('contacts.company_name', 'invoices.currency')
            ->orderByDesc('invoiced')
            ->limit($parameters['limit'])
            ->toBase()
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }
}
