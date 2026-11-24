<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A spare part issued from the inventory to a maintenance work order, with the stock movement that issued it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $work_order_id
 * @property string|null $product_id
 * @property string|null $warehouse_id
 * @property string|null $quantity
 * @property string|null $unit_cost
 * @property string|null $total_cost
 * @property string|null $stock_movement_id
 * @property string|null $consumed_by_user_id
 * @property Carbon|null $consumed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class WorkOrderPart extends BaseEnterpriseModel
{
    protected $table = 'eam_work_order_parts';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:2',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * The work order that consumed the part.
     *
     * @return BelongsTo<MaintenanceWorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(MaintenanceWorkOrder::class, 'work_order_id');
    }
}
