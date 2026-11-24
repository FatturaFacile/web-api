<?php

declare(strict_types=1);

namespace Modules\Wms\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\Warehouse;

/**
 * Criteria that group open pick tasks of a warehouse into a wave: minimum priority, due window, reference prefix and the size bounds of the wave.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $warehouse_id
 * @property string $name
 * @property int|null $min_priority
 * @property int|null $due_within_hours
 * @property string|null $reference_prefix
 * @property int $min_tasks
 * @property int $max_tasks
 * @property int $priority
 * @property bool $is_active
 * @property string|null $notes
 */
final class WmsWaveRule extends BaseEnterpriseModel
{
    protected $table = 'wms_wave_rules';

    protected function casts(): array
    {
        return [
            'min_priority' => 'integer',
            'due_within_hours' => 'integer',
            'min_tasks' => 'integer',
            'max_tasks' => 'integer',
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
