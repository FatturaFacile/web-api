<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Models;

use App\Models\Product;
use App\Models\UnitOfMeasure;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ProductCatalog\Domain\Enums\PackagingRole;

/**
 * A level of the packaging hierarchy of a product (piece pack, carton, pallet). `units_per_parent` says how many of the parent
 * level (or of the base stock unit when there is no parent) it contains; `quantity_base` is that chained into base stock units.
 *
 * @property string $product_id
 * @property string $code
 * @property PackagingRole $role
 * @property string $uom_id
 * @property string|null $parent_packaging_id
 * @property string $units_per_parent
 * @property string $quantity_base
 * @property int $level
 * @property string|null $gtin
 */
final class CatalogPackaging extends BaseEnterpriseModel
{
    protected $table = 'catalog_packagings';

    protected function casts(): array
    {
        return [
            'role' => PackagingRole::class,
            'units_per_parent' => 'decimal:6',
            'quantity_base' => 'decimal:6',
            'level' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return BelongsTo<UnitOfMeasure, $this>
     */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }
}
