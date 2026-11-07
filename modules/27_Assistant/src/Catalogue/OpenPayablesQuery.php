<?php

declare(strict_types=1);

namespace Modules\Assistant\Catalogue;

use App\Models\PassiveInvoice;
use Modules\Assistant\Contracts\CatalogueQuery;

final class OpenPayablesQuery implements CatalogueQuery
{
    public function key(): string
    {
        return 'open_payables';
    }

    public function module(): string
    {
        return 'treasury';
    }

    public function description(): string
    {
        return 'Total still due to suppliers, by currency';
    }

    public function keywords(): array
    {
        return ['debiti verso fornitori', 'dobbiamo ai fornitori', 'da pagare', 'fatture fornitori aperte', 'open payables', 'owe suppliers', 'offene verbindlichkeiten', 'dettes fournisseurs'];
    }

    public function parameters(): array
    {
        return [];
    }

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
