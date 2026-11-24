<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * @property string|null $journal_entry_id
 * @property Carbon|null $gl_posted_at
 * @property Carbon|null $approved_at
 */
final class InventoryAdjustment extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'inventory_adjustments';

    protected function casts(): array
    {
        return [
            'net_valuation_change' => 'decimal:2',
            'is_locked' => 'boolean',
            'approved_at' => 'datetime',
            'gl_posted_at' => 'datetime',
        ];
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
}
