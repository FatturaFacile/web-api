<?php

declare(strict_types=1);

namespace Modules\Assistant\Catalogue;

use App\Models\Invoice;
use Modules\Assistant\Contracts\CatalogueQuery;

final class OverdueReceivablesQuery implements CatalogueQuery
{
    public function key(): string
    {
        return 'overdue_receivables';
    }

    public function module(): string
    {
        return 'invoicing';
    }

    public function description(): string
    {
        return 'Customer invoices past their due date, oldest first';
    }

    public function keywords(): array
    {
        return ['fatture scadute', 'fatture in ritardo', 'clienti in ritardo', 'overdue invoices', 'overdue', 'überfällige rechnungen', 'factures en retard'];
    }

    public function parameters(): array
    {
        return ['limit' => ['min' => 1, 'max' => 50, 'default' => 10]];
    }

    public function run(string $tenantId, array $parameters): array
    {
        return Invoice::query()
            ->where('tenant_id', $tenantId)
            ->where('payment_status', '!=', 'paid')
            ->whereNotIn('invoice_type', ['proforma', 'credit_note'])
            ->whereDate('due_date', '<', now()->toDateString())
            ->orderBy('due_date')
            ->limit($parameters['limit'])
            ->selectRaw('invoice_number, due_date, currency, total_gross - total_paid as open_amount')
            ->toBase()
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }
}
