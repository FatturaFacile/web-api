<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The value a variant carries on one axis of its parent's matrix.
 *
 * @property string $variant_id
 * @property string $attribute_id
 * @property string $attribute_value_id
 */
final class CatalogVariantValue extends BaseEnterpriseModel
{
    protected $table = 'catalog_variant_values';

    /**
     * @return BelongsTo<CatalogAttributeValue, $this>
     */
    public function value(): BelongsTo
    {
        return $this->belongsTo(CatalogAttributeValue::class, 'attribute_value_id');
    }

    /**
     * @return BelongsTo<CatalogAttribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(CatalogAttribute::class, 'attribute_id');
    }
}
