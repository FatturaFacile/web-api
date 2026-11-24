<?php

declare(strict_types=1);

namespace Modules\Assistant\Catalogue;

use Modules\Assistant\Contracts\CatalogueQuery;
use Modules\Treasury\Models\BankAccount;

/**
 * Catalogue query: cash position.
 */
final class CashPositionQuery implements CatalogueQuery
{
    /**
     * Stable key of the query.
     */
    public function key(): string
    {
        return 'cash_position';
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
        return 'Balance of the active bank accounts, by currency';
    }

    /**
     * Phrases that select this query.
     */
    public function keywords(): array
    {
        return ['liquidità', 'liquidita', 'saldo banca', 'saldi bancari', 'quanti soldi', 'cash position', 'bank balance', 'kontostand', 'trésorerie'];
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
        return BankAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->selectRaw('currency, COUNT(*) as accounts, SUM(current_balance) as balance')
            ->groupBy('currency')
            ->orderBy('currency')
            ->toBase()
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }
}
