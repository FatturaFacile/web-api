<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Inventory\Models\StockItem;
use Modules\Manufacturing\Support\Money;

/**
 * An item the manufacturing module plans and produces or buys: raw material, subassembly, finished good or phantom.
 * Stock moves through the linked inventory stock item; the standard cost breakdown is set by the cost rollup.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $item_code
 * @property string $name
 * @property string|null $description
 * @property string $item_type
 * @property string|null $procurement_type
 * @property string|null $stock_item_id
 * @property string|null $product_id
 * @property string|null $default_bom_id
 * @property string|null $default_routing_id
 * @property string|null $default_warehouse_id
 * @property string $uom
 * @property string $standard_cost
 * @property string $current_cost
 * @property string $standard_material_cost
 * @property string $standard_labor_cost
 * @property string $standard_overhead_cost
 * @property string $standard_subcontract_cost
 * @property Carbon|null $standard_cost_rolled_up_at
 * @property string $scrap_rate
 * @property string $safety_stock
 * @property string $min_lot_size
 * @property string|null $max_lot_size
 * @property string|null $lot_size_multiple
 * @property int $lead_time_days
 * @property bool $is_active
 * @property array<string, mixed>|null $custom_attributes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockItem|null $stockItem
 */
final class MfgItem extends BaseEnterpriseModel
{
    protected $table = 'mfg_items';

    protected function casts(): array
    {
        return [
            'standard_cost' => 'decimal:4',
            'current_cost' => 'decimal:4',
            'standard_material_cost' => 'decimal:4',
            'standard_labor_cost' => 'decimal:4',
            'standard_overhead_cost' => 'decimal:4',
            'standard_subcontract_cost' => 'decimal:4',
            'standard_cost_rolled_up_at' => 'datetime',
            'scrap_rate' => 'decimal:2',
            'safety_stock' => 'decimal:4',
            'min_lot_size' => 'decimal:4',
            'max_lot_size' => 'decimal:4',
            'lot_size_multiple' => 'decimal:4',
            'lead_time_days' => 'integer',
            'is_active' => 'boolean',
            'custom_attributes' => 'array',
        ];
    }

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    /**
     * @return BelongsTo<MfgBom, $this>
     */
    public function defaultBom(): BelongsTo
    {
        return $this->belongsTo(MfgBom::class, 'default_bom_id');
    }

    /**
     * @return BelongsTo<MfgRouting, $this>
     */
    public function defaultRouting(): BelongsTo
    {
        return $this->belongsTo(MfgRouting::class, 'default_routing_id');
    }

    /**
     * @return HasMany<MfgBom, $this>
     */
    public function boms(): HasMany
    {
        return $this->hasMany(MfgBom::class, 'mfg_item_id');
    }

    /**
     * @return HasMany<MfgRouting, $this>
     */
    public function routings(): HasMany
    {
        return $this->hasMany(MfgRouting::class, 'mfg_item_id');
    }

    /**
     * @return HasMany<MfgProductionOrder, $this>
     */
    public function productionOrders(): HasMany
    {
        return $this->hasMany(MfgProductionOrder::class, 'mfg_item_id');
    }

    /**
     * @return HasMany<MfgProductionVersion, $this>
     */
    public function productionVersions(): HasMany
    {
        return $this->hasMany(MfgProductionVersion::class, 'mfg_item_id');
    }

    /**
     * How the item is replenished: the explicit procurement type, else buy for raw materials and make for the rest.
     */
    public function procurementType(): string
    {
        if ($this->procurement_type !== null && $this->procurement_type !== '') {
            return $this->procurement_type;
        }

        return $this->isRawMaterial() ? 'buy' : 'make';
    }

    /**
     * Standard unit cost: the rolled-up breakdown when present, else the standard cost entered on the item.
     */
    public function standardUnitCost(): string
    {
        $breakdown = Money::add(Money::add($this->standard_material_cost, $this->standard_labor_cost), Money::add($this->standard_overhead_cost, $this->standard_subcontract_cost));

        return Money::isPositive($breakdown) ? $breakdown : Money::of($this->standard_cost);
    }

    /**
     * Whether the item is a purchased raw material.
     */
    public function isRawMaterial(): bool
    {
        return $this->item_type === 'raw_material';
    }

    /**
     * Whether the item is a finished good.
     */
    public function isFinishedGood(): bool
    {
        return $this->item_type === 'finished_good';
    }

    /**
     * Whether the item is a stocked subassembly.
     */
    public function isSubassembly(): bool
    {
        return $this->item_type === 'subassembly';
    }

    /**
     * Whether the item is a phantom: exploded through, never stocked.
     */
    public function isPhantom(): bool
    {
        return $this->item_type === 'phantom';
    }
}
