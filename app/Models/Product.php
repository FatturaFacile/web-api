<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomsOriginType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTranslations;
use App\Support\MadeInLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property CustomsOriginType|null $origin_type
 */
class Product extends Model
{
    use BelongsToTenant, HasFactory, HasTranslations, HasUuids, SoftDeletes;

    /**
     * @return HasMany<ProductTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ProductTranslation::class, 'product_id');
    }

    protected function getTranslationModelClass(): string
    {
        return ProductTranslation::class;
    }

    protected function getTranslationForeignKey(): string
    {
        return 'product_id';
    }

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
        'customs_taric_code_id',
        'taric_code',
        'country_of_origin',
        'origin_type',
        'made_in_label',
        'preferential_agreement_id',
        'eur1_required',
        'origin_rule_notes',
        'swiss_hs_code',
        'supplementary_unit',
        'customs_duty_rate',
        'net_weight_kg',
        'gross_weight_kg',
        'packages_count',
        'preferential_origin_eligible',
        'track_inventory',
        'is_active',
        'category_id',
        'brand_id',
        'unit_of_measure_id',
        'parent_id',
        'is_variant',
        'attributes',
        'min_stock',
        'product_type',
        'kit_type',
        'purchase_uom_id',
        'sales_uom_id',
        'uom_rounding',
        'uom_precision',
        'price_delta',
        'price_delta_type',
        'cost_delta',
        'cost_delta_type',
        'price_overridden',
        'cost_overridden',
        'vat_code_it_id',
        'vat_code_ch_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (Product $product): void {
            if ($product->country_of_origin !== null) {
                $product->country_of_origin = strtoupper($product->country_of_origin);
                if ($product->made_in_label === null || $product->made_in_label === '') {
                    $product->made_in_label = MadeInLabel::fromCountry($product->country_of_origin);
                }
            }

            // A catalog selection keeps the denormalised code in sync when no explicit code was typed.
            if ($product->customs_taric_code_id !== null && ($product->taric_code === null || $product->taric_code === '')) {
                $product->taric_code = CustomsTaricCode::query()->whereKey($product->customs_taric_code_id)->value('code');
            }
        });
    }

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
            'uom_precision' => 'integer',
            'price_delta' => 'decimal:4',
            'cost_delta' => 'decimal:4',
            'price_overridden' => 'boolean',
            'cost_overridden' => 'boolean',
            'origin_type' => CustomsOriginType::class,
            'eur1_required' => 'boolean',
            'customs_duty_rate' => 'decimal:4',
            'net_weight_kg' => 'decimal:3',
            'gross_weight_kg' => 'decimal:3',
            'preferential_origin_eligible' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<CustomsTaricCode, $this>
     */
    public function customsTaricCode(): BelongsTo
    {
        return $this->belongsTo(CustomsTaricCode::class, 'customs_taric_code_id');
    }

    /**
     * @return BelongsTo<FreeTradeAgreement, $this>
     */
    public function preferentialAgreement(): BelongsTo
    {
        return $this->belongsTo(FreeTradeAgreement::class, 'preferential_agreement_id');
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
