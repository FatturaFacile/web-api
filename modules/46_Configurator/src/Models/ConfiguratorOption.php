<?php

declare(strict_types=1);

namespace Modules\Configurator\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A choice of an option group. It adds a price delta to the configuration and a cost, and multiplies the price of the
 * whole configuration by its multiplier (1 = no effect). With a linked product the option becomes a component of the
 * quote (SKU and stock) and, when price_from_product is set, takes its price from the price lists of the company.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $model_id
 * @property string $group_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $price_delta
 * @property string $price_multiplier
 * @property string $cost
 * @property string|null $product_id
 * @property bool $price_from_product
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort_order
 */
final class ConfiguratorOption extends BaseEnterpriseModel
{
    protected $table = 'cfg_options';

    protected function casts(): array
    {
        return [
            'price_delta' => 'decimal:4',
            'price_multiplier' => 'decimal:4',
            'cost' => 'decimal:4',
            'price_from_product' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ConfiguratorOptionGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ConfiguratorOptionGroup::class, 'group_id');
    }
}
