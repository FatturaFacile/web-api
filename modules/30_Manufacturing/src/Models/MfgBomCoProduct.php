<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A co-product or by-product that a BOM revision yields besides its primary output: the quantity per base quantity
 * and the share of the production cost it carries (the primary output carries the remainder up to 100%).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $bom_id
 * @property string $product_item_id
 * @property string $product_type
 * @property string $output_ratio_qty
 * @property string $cost_allocation_percentage
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MfgItem|null $productItem
 */
final class MfgBomCoProduct extends BaseEnterpriseModel
{
    /** Allowed product types. */
    public const array Types = ['co_product', 'by_product'];

    protected $table = 'mfg_bom_co_products';

    protected function casts(): array
    {
        return [
            'output_ratio_qty' => 'decimal:4',
            'cost_allocation_percentage' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<MfgBom, $this>
     */
    public function bom(): BelongsTo
    {
        return $this->belongsTo(MfgBom::class, 'bom_id');
    }

    /**
     * @return BelongsTo<MfgItem, $this>
     */
    public function productItem(): BelongsTo
    {
        return $this->belongsTo(MfgItem::class, 'product_item_id');
    }
}
