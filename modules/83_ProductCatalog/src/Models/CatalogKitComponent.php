<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Models;

use App\Models\Product;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of the bill of materials of a kit: how many base units of the component make one kit.
 *
 * @property string $kit_id
 * @property string $component_id
 * @property string $quantity
 * @property bool $is_optional
 * @property int $sort_order
 */
final class CatalogKitComponent extends BaseEnterpriseModel
{
    protected $table = 'catalog_kit_components';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'is_optional' => 'boolean', 'sort_order' => 'integer'];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function kit(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'kit_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_id');
    }
}
