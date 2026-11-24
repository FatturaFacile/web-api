<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Models\Product;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Says that a product carries a charge. `quantity_per_unit` is what one unit counts for on the basis of the charge: its
 * weight for a charge per kg, its content for a charge per litre.
 */
final class ProductChargeAssignment extends BaseEnterpriseModel
{
    protected $table = 'product_charge_assignments';

    protected function casts(): array
    {
        return [
            'quantity_per_unit' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<ProductChargeType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ProductChargeType::class, 'product_charge_type_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
