<?php

declare(strict_types=1);

namespace Modules\Assistant\Catalogue;

use Modules\Assistant\Contracts\CatalogueQuery;
use Modules\Procurement\Models\SupplierContract;
use Modules\ServiceManagement\Models\ServiceContract;

/**
 * Catalogue query: expiring contracts.
 */
final class ExpiringContractsQuery implements CatalogueQuery
{
    /**
     * Stable key of the query.
     */
    public function key(): string
    {
        return 'expiring_contracts';
    }

    /**
     * Module key whose read access gates the query.
     */
    public function module(): string
    {
        return 'contracts';
    }

    /**
     * Human description of the query.
     */
    public function description(): string
    {
        return 'Customer and supplier contracts ending soon';
    }

    /**
     * Phrases that select this query.
     */
    public function keywords(): array
    {
        return ['contratti in scadenza', 'contratti scadono', 'expiring contracts', 'contracts ending', 'auslaufende verträge', 'contrats arrivant à échéance'];
    }

    /**
     * Declared parameters with their bounds.
     */
    public function parameters(): array
    {
        return ['days' => ['min' => 1, 'max' => 730, 'default' => 90]];
    }

    /**
     * Run the query for the tenant.
     */
    public function run(string $tenantId, array $parameters): array
    {
        $from = now()->toDateString();
        $until = now()->addDays($parameters['days'])->toDateString();

        $customers = ServiceContract::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereBetween('end_date', [$from, $until])
            ->toBase()
            ->selectRaw("'customer' as kind, contract_number, title, end_date");

        $suppliers = SupplierContract::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereBetween('ends_at', [$from, $until])
            ->toBase()
            ->selectRaw("'supplier' as kind, contract_number, title, ends_at as end_date");

        return $customers->unionAll($suppliers)
            ->orderBy('end_date')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }
}
