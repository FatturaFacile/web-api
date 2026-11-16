<?php

declare(strict_types=1);

namespace Modules\Wms\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;

/**
 * An RF task of the warehouse queue (putaway, pick, replenish, count or move) with priority, assignment and confirmation.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $warehouse_id
 * @property string $task_type
 * @property string $status
 * @property int $priority
 * @property string|null $stock_item_id
 * @property string|null $source_bin_id
 * @property string|null $destination_bin_id
 * @property string $planned_quantity
 * @property string $confirmed_quantity
 * @property string|null $assigned_to
 * @property string|null $wave_id
 * @property string|null $reference
 * @property Carbon|null $due_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property string|null $short_reason
 * @property string|null $skip_reason
 * @property int $skip_count
 * @property string|null $notes
 * @property string|null $stock_posting_key
 * @property string|null $stock_movement_id
 * @property Carbon|null $stock_posted_at
 */
final class WmsTask extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array TaskTypes = ['putaway', 'pick', 'replenish', 'count', 'move'];

    /** @var list<string> */
    public const array Statuses = ['open', 'assigned', 'in_progress', 'completed', 'short_completed', 'skipped', 'cancelled'];

    public const string Open = 'open';

    public const string Assigned = 'assigned';

    public const string InProgress = 'in_progress';

    public const string Completed = 'completed';

    public const string ShortCompleted = 'short_completed';

    public const string Skipped = 'skipped';

    public const string Cancelled = 'cancelled';

    protected $table = 'wms_tasks';

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'planned_quantity' => 'decimal:4',
            'confirmed_quantity' => 'decimal:4',
            'due_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'stock_posted_at' => 'datetime',
            'skip_count' => 'integer',
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
     * @return BelongsTo<StockItem, $this>
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    /**
     * @return BelongsTo<WarehouseBin, $this>
     */
    public function sourceBin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'source_bin_id');
    }

    /**
     * @return BelongsTo<WarehouseBin, $this>
     */
    public function destinationBin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'destination_bin_id');
    }

    /**
     * What is still to be moved after the confirmed quantity (never negative).
     */
    public function remainingQuantity(): string
    {
        $remaining = bcsub((string) $this->planned_quantity, (string) $this->confirmed_quantity, 4);

        return bccomp($remaining, '0', 4) < 0 ? '0.0000' : $remaining;
    }
}
