<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A proposal to buy an item, generated from an inventory reorder rule or from an MRP planned purchase order: open,
 * converted into a purchase order, or dismissed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $source
 * @property string|null $source_id
 * @property string|null $stock_item_id
 * @property string|null $warehouse_id
 * @property string|null $supplier_id
 * @property string|null $supplier_catalog_id
 * @property string $description
 * @property string $quantity
 * @property string $uom
 * @property string|null $unit_price
 * @property Carbon|null $required_by_date
 * @property string $status
 * @property string|null $purchase_order_id
 */
final class PurchaseSuggestion extends BaseEnterpriseModel
{
    protected $table = 'procurement_purchase_suggestions';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'required_by_date' => 'date',
        ];
    }
}
