<?php

declare(strict_types=1);

namespace Modules\Wms\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Inventory\Models\Warehouse;

/**
 * A group of pick tasks released together to the floor. Planned from a wave rule; completes when every task is closed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $warehouse_id
 * @property string|null $wave_rule_id
 * @property string $number
 * @property string $status
 * @property int $task_count
 * @property Carbon|null $released_at
 * @property Carbon|null $completed_at
 * @property string|null $notes
 */
final class WmsWave extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Statuses = ['planned', 'released', 'completed', 'cancelled'];

    public const string Planned = 'planned';

    public const string Released = 'released';

    public const string Completed = 'completed';

    public const string Cancelled = 'cancelled';

    protected $table = 'wms_waves';

    protected function casts(): array
    {
        return [
            'task_count' => 'integer',
            'released_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * @return HasMany<WmsTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(WmsTask::class, 'wave_id');
    }
}
