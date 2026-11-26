<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An attribute chosen as an axis of the variant matrix of a parent product; the sort order is the order of the SKU tokens.
 *
 * @property string $product_id
 * @property string $attribute_id
 * @property int $sort_order
 */
final class CatalogProductAxis extends BaseEnterpriseModel
{
    protected $table = 'catalog_product_axes';

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /**
     * @return BelongsTo<CatalogAttribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(CatalogAttribute::class, 'attribute_id');
    }
}
