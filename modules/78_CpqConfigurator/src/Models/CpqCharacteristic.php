<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A question of the guided configuration: a value list, a numeric range with minimum, maximum and step, or a boolean.
 * `part_number` says how the answer enters the smart part number: {position, true_code, false_code, prefix}.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $model_id
 * @property string $code
 * @property string|null $step_code
 * @property string $type
 * @property array<string, string> $label
 * @property array<string, string>|null $help
 * @property string|null $unit
 * @property string|null $range_min
 * @property string|null $range_max
 * @property string|null $range_step
 * @property string|null $default_value
 * @property bool $is_required
 * @property array<string, mixed>|null $part_number
 * @property array<string, mixed>|null $visual {on: layer} for a boolean, the layer shown while it is on
 * @property int $sort_order
 */
final class CpqCharacteristic extends BaseEnterpriseModel
{
    public const string TypeList = 'list';

    public const string TypeRange = 'range';

    public const string TypeBoolean = 'boolean';

    /** @var list<string> */
    public const array Types = [self::TypeList, self::TypeRange, self::TypeBoolean];

    protected $table = 'cpq_characteristics';

    protected function casts(): array
    {
        return [
            'label' => 'array',
            'help' => 'array',
            'range_min' => 'decimal:4',
            'range_max' => 'decimal:4',
            'range_step' => 'decimal:4',
            'is_required' => 'boolean',
            'part_number' => 'array',
            'visual' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CpqModel, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(CpqModel::class, 'model_id');
    }

    /**
     * @return HasMany<CpqCharacteristicOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(CpqCharacteristicOption::class, 'characteristic_id')->orderBy('sort_order')->orderBy('code');
    }
}
