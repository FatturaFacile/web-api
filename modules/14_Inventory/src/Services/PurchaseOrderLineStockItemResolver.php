<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockItem;

/**
 * The stock item a purchase order line delivers. An order line has no product column: it names the supplier catalog
 * entry, whose internal SKU is the SKU of the stock item. Only one exact match counts; anything else gives no item, so
 * nothing is ever booked on a guess.
 */
final class PurchaseOrderLineStockItemResolver
{
    public function resolve(string $purchaseOrderLineId, string $tenantId): ?StockItem
    {
        $sku = DB::table('purchase_order_lines')
            ->join('supplier_catalogs', 'supplier_catalogs.id', '=', 'purchase_order_lines.supplier_catalog_id')
            ->where('purchase_order_lines.id', $purchaseOrderLineId)
            ->where('purchase_order_lines.tenant_id', $tenantId)
            ->value('supplier_catalogs.internal_sku');

        if (! is_string($sku) || $sku === '') {
            return null;
        }

        $items = StockItem::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('sku', $sku)->limit(2)->get();

        return $items->count() === 1 ? $items->first() : null;
    }
}
