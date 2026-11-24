<?php

declare(strict_types=1);

namespace Modules\Configurator\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A question of the configuration: choose one option (single), any number of options (multi), enable or disable one
 * feature (boolean) or enter a number within bounds (range). A range is priced per unit by the group itself, a boolean
 * group owns exactly one option, which is the feature.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $model_id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property bool $is_required
 * @property int|null $min_select
 * @property int|null $max_select
 * @property string|null $range_min
 * @property string|null $range_max
 * @property string|null $range_step
 * @property string|null $default_value
 * @property string|null $unit
 * @property string $unit_price_delta
 * @property string $unit_cost
 * @property string|null $product_id
 * @property int $sort_order
 */
final class ConfiguratorOptionGroup extends BaseEnterpriseModel
{
    public const string Single = 'single';

    public const string Multi = 'multi';

    public const string Range = 'range';

    public const string Boolean = 'boolean';

    /** @var list<string> */
    public const array Types = [self::Single, self::Multi, self::Range, self::Boolean];

    protected $table = 'cfg_option_groups';

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'min_select' => 'integer',
            'max_select' => 'integer',
            'range_min' => 'decimal:4',
            'range_max' => 'decimal:4',
            'range_step' => 'decimal:4',
            'default_value' => 'decimal:4',
            'unit_price_delta' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ConfigurableModel, $this>
     */
    public function configurableModel(): BelongsTo
    {
        return $this->belongsTo(ConfigurableModel::class, 'model_id');
    }

    /**
     * @return HasMany<ConfiguratorOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ConfiguratorOption::class, 'group_id')->orderBy('sort_order')->orderBy('code');
    }
}
