<?php

declare(strict_types=1);

namespace Modules\Configurator\Models;

use App\Models\Product;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A configurable product: the base price and cost, the option groups the customer chooses from, the rules over the
 * chosen options and the quantity breaks. Every structural change of a published model raises its version, so a
 * configuration and its quote can say which version priced them.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $base_product_id
 * @property bool $base_price_from_product
 * @property string $base_price
 * @property string $base_cost
 * @property string $currency
 * @property string $tax_rate
 * @property string|null $min_margin_percent
 * @property list<array{min_quantity: string, discount_percent: string}>|null $volume_breaks
 * @property string $status
 * @property int $version
 * @property Carbon|null $published_at
 */
final class ConfigurableModel extends BaseEnterpriseModel
{
    public const string Draft = 'draft';

    public const string Published = 'published';

    public const string Archived = 'archived';

    /** @var array<string, list<string>> */
    public const array Transitions = [
        self::Draft => [self::Published, self::Archived],
        self::Published => [self::Archived],
        self::Archived => [],
    ];

    protected $table = 'cfg_models';

    protected function casts(): array
    {
        return [
            'base_price_from_product' => 'boolean',
            'base_price' => 'decimal:4',
            'base_cost' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'min_margin_percent' => 'decimal:2',
            'volume_breaks' => 'array',
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ConfiguratorOptionGroup, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(ConfiguratorOptionGroup::class, 'model_id')->orderBy('sort_order')->orderBy('code');
    }

    /**
     * @return HasMany<ConfiguratorOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ConfiguratorOption::class, 'model_id')->orderBy('sort_order')->orderBy('code');
    }

    /**
     * @return HasMany<ConfiguratorRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(ConfiguratorRule::class, 'model_id')->orderBy('priority')->orderBy('created_at');
    }

    /**
     * @return HasMany<ProductConfiguration, $this>
     */
    public function configurations(): HasMany
    {
        return $this->hasMany(ProductConfiguration::class, 'model_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function baseProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'base_product_id');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::Transitions[$this->status] ?? [], true);
    }

    public function isEditable(): bool
    {
        return $this->status !== self::Archived;
    }
}
