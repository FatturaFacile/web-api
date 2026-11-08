<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Models\Product;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StockItem extends BaseEnterpriseModel
{
    protected $table = 'stock_items';

    protected function casts(): array
    {
        return [
            'standard_cost' => 'decimal:4',
            'current_moving_average_cost' => 'decimal:4',
            'weight_kg' => 'decimal:3',
            'net_weight_kg' => 'decimal:3',
            'volume_m3' => 'decimal:5',
            'carton_tare_kg' => 'decimal:3',
            'pallet_tare_kg' => 'decimal:3',
            'shelf_life_days' => 'integer',
            'min_remaining_shelf_life_days' => 'integer',
            'units_per_carton' => 'integer',
            'cartons_per_pallet' => 'integer',
            'is_active' => 'boolean',
            'abc_classified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return BelongsTo<StockItemCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(StockItemCategory::class, 'category_id');
    }

    /**
     * @return HasMany<StockLot, $this>
     */
    public function lots(): HasMany
    {
        return $this->hasMany(StockLot::class, 'stock_item_id');
    }

    /**
     * @return HasMany<StockSerialNumber, $this>
     */
    public function serialNumbers(): HasMany
    {
        return $this->hasMany(StockSerialNumber::class, 'stock_item_id');
    }

    /**
     * @return HasMany<StockQuant, $this>
     */
    public function quants(): HasMany
    {
        return $this->hasMany(StockQuant::class, 'stock_item_id');
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'stock_item_id');
    }

    /**
     * @return HasMany<StockReservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class, 'stock_item_id');
    }

    /**
     * @return HasMany<StockReorderRule, $this>
     */
    public function reorderRules(): HasMany
    {
        return $this->hasMany(StockReorderRule::class, 'stock_item_id');
    }

    public function getTotalOnHandAttribute(): float
    {
        return (float) $this->quants()->sum('quantity_on_hand');
    }

    public function getTotalReservedAttribute(): float
    {
        return (float) $this->quants()->sum('quantity_reserved');
    }

    public function getTotalAvailableAttribute(): float
    {
        return max(0.0, $this->total_on_hand - $this->total_reserved);
    }
}
