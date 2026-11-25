<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A choice of a value-list characteristic. `properties` holds technical values the formulas read as
 * `attr.<characteristic>.<name>` (density, power…); `visual` describes the layer the option adds to the preview
 * ({layer, color, label, z}).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $model_id
 * @property string $characteristic_id
 * @property string $code
 * @property array<string, string> $label
 * @property array<string, string>|null $description
 * @property string|null $part_number_code
 * @property bool $is_default
 * @property bool $is_active
 * @property array<string, mixed>|null $properties
 * @property array<string, mixed>|null $visual
 * @property int $sort_order
 */
final class CpqCharacteristicOption extends BaseEnterpriseModel
{
    protected $table = 'cpq_characteristic_options';

    protected function casts(): array
    {
        return [
            'label' => 'array',
            'description' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'properties' => 'array',
            'visual' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CpqCharacteristic, $this>
     */
    public function characteristic(): BelongsTo
    {
        return $this->belongsTo(CpqCharacteristic::class, 'characteristic_id');
    }
}
