<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One value of a variant axis (M, Red, Cotton). The label is a map locale => text, the SKU token is the fragment added to the
 * SKU of every variant that carries the value.
 *
 * @property string $attribute_id
 * @property string $code
 * @property array<string, string> $label
 * @property string $sku_token
 * @property string|null $price_delta
 * @property string|null $price_delta_type
 * @property string|null $cost_delta
 * @property string|null $cost_delta_type
 * @property int $sort_order
 * @property bool $is_active
 */
final class CatalogAttributeValue extends BaseEnterpriseModel
{
    protected $table = 'catalog_attribute_values';

    protected function casts(): array
    {
        return [
            'label' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'price_delta' => 'decimal:4',
            'cost_delta' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<CatalogAttribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(CatalogAttribute::class, 'attribute_id');
    }

    public function labelIn(string $locale): string
    {
        return (string) ($this->label[$locale] ?? $this->label['en'] ?? $this->code);
    }
}
