<?php

declare(strict_types=1);

namespace Modules\Assistant\Catalogue;

use App\Models\PassiveInvoice;
use Modules\Assistant\Contracts\CatalogueQuery;

/**
 * Catalogue query: invoices awaiting approval.
 */
final class InvoicesAwaitingApprovalQuery implements CatalogueQuery
{
    /**
     * Stable key of the query.
     */
    public function key(): string
    {
        return 'invoices_awaiting_approval';
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
        return 'Supplier invoices waiting for approval, by state';
    }

    /**
     * Phrases that select this query.
     */
    public function keywords(): array
    {
        return ['da approvare', 'in approvazione', 'awaiting approval', 'pending approval', 'zur freigabe', 'en attente d\'approbation'];
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
