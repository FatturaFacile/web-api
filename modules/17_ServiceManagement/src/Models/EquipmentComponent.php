<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Component installed in a serialized equipment (as-built at sale, as-maintained after repairs).
 *
 * @property string $id
 * @property string $equipment_passport_id
 * @property string $position
 * @property string $component_serial
 * @property string|null $component_product_id
 * @property string|null $role
 * @property string $source
 * @property \Illuminate\Support\Carbon|null $installed_at
 * @property \Illuminate\Support\Carbon|null $removed_at
 * @property string|null $replaced_by_component_id
 * @property string|null $rma_order_id
 */
final class EquipmentComponent extends BaseEnterpriseModel
{
    public const SOURCE_AS_BUILT = 'as_built';

    public const SOURCE_AS_MAINTAINED = 'as_maintained';

    protected $table = 'esm_equipment_components';

    protected function casts(): array
    {
        return [
            'installed_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SerializedEquipmentPassport, $this>
     */
    public function passport(): BelongsTo
    {
        return $this->belongsTo(SerializedEquipmentPassport::class, 'equipment_passport_id');
    }
}
