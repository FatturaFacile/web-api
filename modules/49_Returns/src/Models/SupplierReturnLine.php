<?php

declare(strict_types=1);

namespace Modules\Returns\Models;

use App\Models\Product;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product line of a return to the supplier, optionally tied to the goods receipt line it came in on.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $supplier_return_id
 * @property string $product_id
 * @property string|null $description
 * @property string $quantity
 * @property string $unit_cost
 * @property string|null $goods_receipt_item_id
 * @property string|null $rma_line_id
 * @property string|null $serial_number
 * @property string|null $lot_number
 * @property string|null $stock_movement_id
 */
final class SupplierReturnLine extends BaseEnterpriseModel
{
    protected $table = 'returns_supplier_return_lines';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit_cost' => 'decimal:4'];
    }

    /**
     * @return BelongsTo<SupplierReturn, $this>
     */
    public function supplierReturn(): BelongsTo
    {
        return $this->belongsTo(SupplierReturn::class, 'supplier_return_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
