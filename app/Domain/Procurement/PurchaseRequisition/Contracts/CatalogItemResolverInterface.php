<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\Contracts;

/**
 * Resolves catalog items (from supplier catalogs or inventory products) scoped to the active tenant.
 */
interface CatalogItemResolverInterface
{
    /**
     * @return array{id: string, supplier_catalog_id: ?string, suggested_supplier_id: ?string, description: string, unit_of_measure: string, estimated_unit_price: string}|null
     */
    public function resolve(string $tenantId, string $catalogItemId): ?array;
}
