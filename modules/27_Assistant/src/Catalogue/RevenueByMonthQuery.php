<?php

declare(strict_types=1);

namespace Modules\Assistant\Catalogue;

use App\Models\Invoice;
use Modules\Assistant\Contracts\CatalogueQuery;

final class RevenueByMonthQuery implements CatalogueQuery
{
    public function key(): string
    {
        return 'revenue_by_month';
    }

    public function module(): string
    {
        return 'invoicing';
    }

    public function description(): string
    {
        return 'Invoiced amount by month of a year, before tax';
    }

    public function keywords(): array
    {
        return ['fatturato', 'ricavi', 'revenue', 'turnover', 'umsatz', "chiffre d'affaires"];
    }

    public function parameters(): array
    {
        return ['year' => ['min' => 2000, 'max' => 2100, 'default' => (int) now()->year]];
    }

    public function run(string $tenantId, array $parameters): array
    {
        return Invoice::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('invoice_type', ['proforma', 'credit_note'])
            ->whereYear('date', $parameters['year'])
            ->selectRaw('EXTRACT(MONTH FROM date)::int as month, currency, SUM(total_net) as revenue')
            ->groupByRaw('EXTRACT(MONTH FROM date), currency')
            ->orderBy('month')
            ->toBase()
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }
}
