<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persisted allocation of an applied landed cost document onto one cost layer (receipt/item/lot).
 *
 * @property string $landed_cost_document_id
 * @property string $purchase_receipt_id
 * @property string $stock_item_id
 * @property string|null $lot_id
 * @property string|null $cost_layer_id
 * @property string $valuation_method
 * @property string $basis_value
 * @property string $allocated_amount
 * @property string $to_inventory_amount
 * @property string $to_cogs_amount
 * @property string $to_variance_amount
 * @property string $layer_unit_uplift
 * @property string $average_cost_uplift
 */
final class LandedCostAllocation extends BaseEnterpriseModel
{
    protected $table = 'landed_cost_allocations';

    protected function casts(): array
    {
        return [
            'basis_value' => 'decimal:6',
            'allocated_amount' => 'decimal:2',
            'to_inventory_amount' => 'decimal:2',
            'to_cogs_amount' => 'decimal:2',
            'to_variance_amount' => 'decimal:2',
            'layer_unit_uplift' => 'decimal:4',
            'average_cost_uplift' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<LandedCostDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(LandedCostDocument::class, 'landed_cost_document_id');
    }

    /**
     * @return BelongsTo<InventoryCostLayer, $this>
     */
    public function layer(): BelongsTo
    {
        return $this->belongsTo(InventoryCostLayer::class, 'cost_layer_id');
    }
}
