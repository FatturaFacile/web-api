<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    /**
     * Key of `attributes` that marks end-of-series products.
     */
    public const DISCONTINUE_FLAG = 'discontinue_on_zero_stock';

    protected $fillable = [
        'tenant_id',
        'sku',
        'barcode',
        'name',
        'description',
        'unit_measure',
        'cost_price',
        'sale_price',
        'tax_rate',
        'hs_code',
        'track_inventory',
        'is_active',
        'category_id',
        'brand_id',
        'unit_of_measure_id',
        'parent_id',
        'is_variant',
        'attributes',
        'min_stock',
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:4',
            'sale_price' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'track_inventory' => 'boolean',
            'is_active' => 'boolean',
            'is_variant' => 'boolean',
            'attributes' => 'array',
            'min_stock' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<ProductBrand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(ProductBrand::class, 'brand_id');
    }

    /**
     * The parent of a variant.
     *
     * @return BelongsTo<Product, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * The variants (size, colour, ...) of a parent product.
     *
     * @return HasMany<Product, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Only variant rows.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeVariantsOnly(Builder $query): void
    {
        $query->where('is_variant', true);
    }

    /**
     * Simple products and variant parents, without the variant rows.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeWithoutVariants(Builder $query): void
    {
        $query->where('is_variant', false);
    }

    /**
     * The attribute (size, colour, ...) values of a variant, without lifecycle flags stored in the same JSON column.
     *
     * @return array<string, string>
     */
    public function optionAttributes(): array
    {
        return array_map('strval', array_diff_key((array) $this->getAttribute('attributes'), [self::DISCONTINUE_FLAG => true]));
    }

    /**
     * Whether the product is switched off automatically when its stock reaches zero.
     */
    public function discontinuesOnZeroStock(): bool
    {
        return (bool) (((array) $this->getAttribute('attributes'))[self::DISCONTINUE_FLAG] ?? false);
    }
}
