<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategory extends Model
{
    use BelongsToTenant, HasFactory, HasTranslations, HasUuids;

    /**
     * @return HasMany<ProductCategoryTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ProductCategoryTranslation::class, 'product_category_id');
    }

    protected function getTranslationModelClass(): string
    {
        return ProductCategoryTranslation::class;
    }

    protected function getTranslationForeignKey(): string
    {
        return 'product_category_id';
    }

    protected $fillable = [
        'tenant_id',
        'parent_id',
        'name',
    ];

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
