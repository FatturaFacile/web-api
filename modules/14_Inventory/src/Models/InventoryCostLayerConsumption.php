<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $layer_id
 * @property string $stock_item_id
 * @property string $issue_movement_id
 * @property string $quantity
 * @property string $unit_cost
 * @property string $cogs_amount
 */
final class InventoryCostLayerConsumption extends BaseEnterpriseModel
{
    protected $table = 'inventory_cost_layer_consumptions';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'cogs_amount' => 'decimal:2',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<InventoryCostLayer, $this>
     */
    public function layer(): BelongsTo
    {
        return $this->belongsTo(InventoryCostLayer::class, 'layer_id');
    }

    /**
     * @return BelongsTo<StockMovement, $this>
     */
    public function issueMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'issue_movement_id');
    }
}
