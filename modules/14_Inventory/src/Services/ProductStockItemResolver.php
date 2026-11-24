<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockItemCategory;

/**
 * The stock item that holds the stock of a catalog product (`stock_items.product_id`, one per product and tenant).
 *
 * The catalog (app) sells products, the inventory module keeps stock per stock item: every stock movement of a product
 * goes through its stock item. A product without one gets it on its first movement: an unlinked stock item with the
 * same SKU is adopted (the SKU is also what links supplier catalog entries to stock items, see
 * PurchaseOrderLineStockItemResolver), otherwise a new one is created in the tenant's general category.
 */
final class ProductStockItemResolver
{
    public const string DEFAULT_CATEGORY_CODE = 'GENERAL';

    /**
     * The linked stock item, or null when the product never moved stock. Reads must not create items.
     */
    public function find(string $productId, string $tenantId): ?StockItem
    {
        return StockItem::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * The linked stock item, created or adopted when missing. The product row is locked first, so two concurrent first
     * movements of the same product cannot create two stock items.
     */
    public function resolve(Product $product): StockItem
    {
        $tenantId = (string) $product->tenant_id;
        $existing = $this->find((string) $product->id, $tenantId);

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($product, $tenantId): StockItem {
            Product::withoutGlobalScopes()->whereKey($product->id)->lockForUpdate()->first();

            $existing = $this->find((string) $product->id, $tenantId);

            if ($existing !== null) {
                return $existing;
            }

            $sku = (string) ($product->sku ?: $product->id);
            $sameSku = StockItem::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('sku', $sku)->first();

            if ($sameSku !== null && $sameSku->product_id === null && $sameSku->deleted_at === null) {
                StockItem::withoutGlobalScopes()->whereKey($sameSku->id)->update(['product_id' => $product->id, 'updated_at' => now()]);

                return $sameSku->refresh();
            }

            if ($sameSku !== null) {
                $sku = Str::limit($sku, 90, '').'-'.substr((string) $product->id, -8);
            }

            $item = new StockItem([
                'company_id' => null,
                'category_id' => $this->defaultCategory($tenantId)->id,
                'product_id' => $product->id,
                'sku' => $sku,
                'name' => Str::limit((string) $product->name, 255, ''),
                'description' => $product->description,
                'barcode_ean13' => is_string($product->barcode) && preg_match('/^\d{13}$/', $product->barcode) === 1 ? $product->barcode : null,
                'barcode_custom' => is_string($product->barcode) && preg_match('/^\d{13}$/', $product->barcode) !== 1 ? Str::limit($product->barcode, 100, '') : null,
                'uom' => Str::limit((string) ($product->unit_measure ?: 'PCE'), 20, ''),
                'tracking_type' => 'none',
                'standard_cost' => (float) $product->cost_price,
                'current_moving_average_cost' => (float) $product->cost_price,
                'is_active' => true,
            ]);
            $item->tenant_id = $tenantId;
            $item->save();

            return $item;
        });
    }

    /**
     * The category new product stock items go to, created once per tenant. `createOrFirst` keeps two concurrent
     * creations from failing on the unique code.
     */
    private function defaultCategory(string $tenantId): StockItemCategory
    {
        $category = StockItemCategory::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('code', self::DEFAULT_CATEGORY_CODE)->first();

        if ($category !== null) {
            return $category;
        }

        /** @var StockItemCategory $category */
        $category = StockItemCategory::withoutGlobalScopes()->createOrFirst(
            ['tenant_id' => $tenantId, 'code' => self::DEFAULT_CATEGORY_CODE],
            ['name' => 'General', 'valuation_method' => 'moving_average'],
        );

        return $category;
    }
}
