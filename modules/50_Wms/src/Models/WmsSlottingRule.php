<?php

declare(strict_types=1);

namespace Modules\Wms\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\Warehouse;

/**
 * Says which kind of bin an item should live in, by ABC class and pick velocity (picks per day).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $warehouse_id
 * @property string $name
 * @property string|null $abc_class
 * @property string|null $min_velocity
 * @property string|null $max_velocity
 * @property string $target_bin_type
 * @property string|null $target_zone_id
 * @property int $priority
 * @property bool $is_active
 * @property string|null $notes
 */
final class WmsSlottingRule extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array AbcClasses = ['A', 'B', 'C'];

    /** @var list<string> */
    public const array TargetBinTypes = ['storage', 'picking', 'bulk', 'receiving', 'staging', 'shipping', 'quarantine', 'transit'];

    protected $table = 'wms_slotting_rules';

    protected function casts(): array
    {
        return [
            'min_velocity' => 'decimal:4',
            'max_velocity' => 'decimal:4',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
