<?php

declare(strict_types=1);

namespace Modules\Assistant\Catalogue;

use Modules\Assistant\Contracts\CatalogueQuery;
use Modules\Treasury\Models\BankAccount;

final class CashPositionQuery implements CatalogueQuery
{
    public function key(): string
    {
        return 'cash_position';
    }

    public function module(): string
    {
        return 'treasury';
    }

    public function description(): string
    {
        return 'Balance of the active bank accounts, by currency';
    }

    public function keywords(): array
    {
        return ['liquidità', 'liquidita', 'saldo banca', 'saldi bancari', 'quanti soldi', 'cash position', 'bank balance', 'kontostand', 'trésorerie'];
    }

    public function parameters(): array
    {
        return [];
    }

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
