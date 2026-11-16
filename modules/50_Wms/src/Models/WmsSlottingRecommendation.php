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
 * A suggested move of an item to a better bin, computed from its pick velocity and ABC class. Accepting it creates a move task.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $warehouse_id
 * @property string $stock_item_id
 * @property string|null $slotting_rule_id
 * @property string $abc_class
 * @property string $velocity
 * @property int $period_days
 * @property string|null $from_bin_id
 * @property string $to_bin_id
 * @property string $target_bin_type
 * @property string $status
 * @property string|null $task_id
 * @property Carbon|null $decided_at
 * @property string|null $reason
 */
final class WmsSlottingRecommendation extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array AbcClasses = ['A', 'B', 'C'];

    /** @var list<string> */
    public const array Statuses = ['pending', 'accepted', 'rejected'];

    public const string Pending = 'pending';

    public const string Accepted = 'accepted';

    public const string Rejected = 'rejected';

    protected $table = 'wms_slotting_recommendations';

    protected function casts(): array
    {
        return [
            'velocity' => 'decimal:4',
            'period_days' => 'integer',
            'decided_at' => 'datetime',
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
    public function fromBin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'from_bin_id');
    }

    /**
     * @return BelongsTo<WarehouseBin, $this>
     */
    public function toBin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'to_bin_id');
    }
}
