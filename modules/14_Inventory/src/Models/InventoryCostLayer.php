<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Inventory\Support\Decimal;

/**
 * A receipt (or opening balance) cost layer. FIFO depletes `remaining_quantity` in
 * `received_at` order; landed costs uplift `landed_cost_unit`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $stock_item_id
 * @property string|null $warehouse_id
 * @property string|null $lot_id
 * @property string|null $source_movement_id
 * @property string $source_type
 * @property string|null $receipt_id
 * @property Carbon $received_at
 * @property int $layer_sequence
 * @property string $original_quantity
 * @property string $remaining_quantity
 * @property string $unit_cost
 * @property string $landed_cost_unit
 * @property string $currency
 */
final class InventoryCostLayer extends BaseEnterpriseModel
{
    protected $table = 'inventory_cost_layers';

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'layer_sequence' => 'integer',
            'original_quantity' => 'decimal:4',
            'remaining_quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'landed_cost_unit' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    /**
     * @return BelongsTo<StockLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    /**
     * @return HasMany<InventoryCostLayerConsumption, $this>
     */
    public function consumptions(): HasMany
    {
        return $this->hasMany(InventoryCostLayerConsumption::class, 'layer_id');
    }

    public function effectiveUnitCost(): string
    {
        return Decimal::unitCost(Decimal::add((string) $this->unit_cost, (string) $this->landed_cost_unit));
    }

    public function consumedQuantity(): string
    {
        return Decimal::quantity(Decimal::sub((string) $this->original_quantity, (string) $this->remaining_quantity));
    }
}
