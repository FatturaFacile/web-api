<?php

declare(strict_types=1);

namespace Modules\Assistant\Catalogue;

use App\Models\PassiveInvoice;
use Modules\Assistant\Contracts\CatalogueQuery;

final class InvoicesAwaitingApprovalQuery implements CatalogueQuery
{
    public function key(): string
    {
        return 'invoices_awaiting_approval';
    }

    public function module(): string
    {
        return 'treasury';
    }

    public function description(): string
    {
        return 'Supplier invoices waiting for approval, by state';
    }

    public function keywords(): array
    {
        return ['da approvare', 'in approvazione', 'awaiting approval', 'pending approval', 'zur freigabe', 'en attente d\'approbation'];
    }

    public function parameters(): array
    {
        return [];
    }

    public function run(string $tenantId, array $parameters): array
    {
        return PassiveInvoice::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('approval_status', ['pending', 'in_approval'])
            ->selectRaw('approval_status, currency, COUNT(*) as invoices, SUM(total_amount) as amount')
            ->groupBy('approval_status', 'currency')
            ->orderBy('approval_status')
            ->toBase()
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }
}
