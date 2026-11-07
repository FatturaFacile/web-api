<?php

declare(strict_types=1);

namespace Modules\Assistant\Catalogue;

use Modules\Assistant\Contracts\CatalogueQuery;
use Modules\Procurement\Models\SupplierContract;
use Modules\ServiceManagement\Models\ServiceContract;

final class ExpiringContractsQuery implements CatalogueQuery
{
    public function key(): string
    {
        return 'expiring_contracts';
    }

    public function module(): string
    {
        return 'contracts';
    }

    public function description(): string
    {
        return 'Customer and supplier contracts ending soon';
    }

    public function keywords(): array
    {
        return ['contratti in scadenza', 'contratti scadono', 'expiring contracts', 'contracts ending', 'auslaufende verträge', 'contrats arrivant à échéance'];
    }

    public function parameters(): array
    {
        return ['days' => ['min' => 1, 'max' => 730, 'default' => 90]];
    }

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
