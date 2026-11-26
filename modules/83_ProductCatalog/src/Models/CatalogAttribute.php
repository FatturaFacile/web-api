<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A variant axis (size, colour, material, ...). The name is a map locale => text; the SKU token is the fragment it never adds to a
 * SKU itself (the tokens belong to the values), kept for exports and as a fallback prefix.
 *
 * @property string $code
 * @property array<string, string> $name
 * @property string $sku_token
 * @property int $sort_order
 * @property bool $is_active
 */
final class CatalogAttribute extends BaseEnterpriseModel
{
    protected $table = 'catalog_attributes';

    protected function casts(): array
    {
        return ['name' => 'array', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /**
     * @return HasMany<CatalogAttributeValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(CatalogAttributeValue::class, 'attribute_id')->orderBy('sort_order')->orderBy('code');
    }

    /**
     * The name in a locale, falling back to English and then to the code.
     */
    public function nameIn(string $locale): string
    {
        return (string) ($this->name[$locale] ?? $this->name['en'] ?? $this->code);
    }
}
