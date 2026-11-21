<?php

declare(strict_types=1);

namespace App\Infrastructure\Procurement\PurchaseRequisition\Services;

use App\Domain\Procurement\PurchaseRequisition\Contracts\CatalogItemResolverInterface;
use App\Models\Product;
use Modules\Procurement\Models\SupplierCatalog;
use Modules\Procurement\Models\SupplierPriceList;
use Modules\Procurement\Support\Decimal;

/**
 * Resolves catalog items from tenant SupplierCatalog or inventory Product records.
 */
final class CatalogItemResolverAdapter implements CatalogItemResolverInterface
{
    public function resolve(string $tenantId, string $catalogItemId): ?array
    {
        $supplierCatalog = SupplierCatalog::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($catalogItemId)
            ->first();

        if ($supplierCatalog !== null) {
            $today = now()->toDateString();
            $price = SupplierPriceList::query()
                ->where('tenant_id', $tenantId)
                ->where('supplier_catalog_id', $supplierCatalog->id)
                ->where(static fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $today))
                ->where(static fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $today))
                ->orderBy('min_quantity')
                ->value('unit_price');

            return [
                'id' => (string) $supplierCatalog->id,
                'supplier_catalog_id' => (string) $supplierCatalog->id,
                'suggested_supplier_id' => (string) $supplierCatalog->supplier_id,
                'description' => (string) ($supplierCatalog->description ?: $supplierCatalog->name),
                'unit_of_measure' => (string) ($supplierCatalog->uom ?: 'PCS'),
                'estimated_unit_price' => Decimal::of($price ?? '0', 4),
            ];
        }

        $product = Product::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($catalogItemId)
            ->first();

        if ($product !== null) {
            return [
                'id' => (string) $product->id,
                'supplier_catalog_id' => null,
                'suggested_supplier_id' => null,
                'description' => (string) ($product->name ?: $product->description ?: $product->sku),
                'unit_of_measure' => (string) ($product->unit_measure ?: 'PCS'),
                'estimated_unit_price' => Decimal::of((string) ($product->cost_price ?? $product->sale_price ?? '0'), 4),
            ];
        }

        return null;
    }
}
