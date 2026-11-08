<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * @property Carbon|null $effective_at
 * @property Carbon|null $scheduled_date
 * @property Carbon|null $completed_at
 * @property Carbon|null $frozen_at
 * @property Carbon|null $approved_at
 */
final class InventoryCountSession extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'inventory_count_sessions';

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'scheduled_date' => 'date',
            'is_locked' => 'boolean',
            'completed_at' => 'datetime',
            'freezes_stock' => 'boolean',
            'frozen_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * The zone the session is limited to, when it counts one zone only.
     *
     * @return BelongsTo<WarehouseZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(WarehouseZone::class, 'warehouse_zone_id');
    }

    /**
     * The cycle count plan that generated the session, if any.
     *
     * @return BelongsTo<CycleCountPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(CycleCountPlan::class, 'cycle_count_plan_id');
    }

    public function lockByWorkflow(WorkflowInstance $instance): void
    {
        $this->update(['is_locked' => true]);
    }

    public function isLockedByWorkflow(): bool
    {
        return (bool) $this->is_locked;
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * @return HasMany<InventoryCountLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryCountLine::class, 'inventory_count_session_id');
    }
}
