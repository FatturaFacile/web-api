<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A recurring cycle count of a warehouse: every `interval_days` the items of one ABC class (or every item when no
 * class is set) are put in a new count session, optionally freezing their stock while they are counted. The interval
 * is the company's choice: a plan has no default frequency.
 *
 * @property Carbon|null $last_run_at
 * @property Carbon|null $next_run_on
 */
final class CycleCountPlan extends BaseEnterpriseModel
{
    protected $table = 'cycle_count_plans';

    protected function casts(): array
    {
        return [
            'interval_days' => 'integer',
            'freezes_stock' => 'boolean',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_on' => 'date',
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
     * @return HasMany<InventoryCountSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(InventoryCountSession::class, 'cycle_count_plan_id');
    }
}
