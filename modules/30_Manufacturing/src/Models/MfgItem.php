<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Models\StockItem;

final class MfgItem extends BaseEnterpriseModel
{
    protected $table = 'mfg_items';

    protected function casts(): array
    {
        return [
            'standard_cost' => 'decimal:4',
            'current_cost' => 'decimal:4',
            'scrap_rate' => 'decimal:2',
            'safety_stock' => 'decimal:4',
            'min_lot_size' => 'decimal:4',
            'max_lot_size' => 'decimal:4',
            'lead_time_days' => 'integer',
            'is_active' => 'boolean',
            'custom_attributes' => 'array',
        ];
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function defaultBom(): BelongsTo
    {
        return $this->belongsTo(MfgBom::class, 'default_bom_id');
    }

    public function defaultRouting(): BelongsTo
    {
        return $this->belongsTo(MfgRouting::class, 'default_routing_id');
    }

    public function boms(): HasMany
    {
        return $this->hasMany(MfgBom::class, 'mfg_item_id');
    }

    public function routings(): HasMany
    {
        return $this->hasMany(MfgRouting::class, 'mfg_item_id');
    }

    public function productionOrders(): HasMany
    {
        return $this->hasMany(MfgProductionOrder::class, 'mfg_item_id');
    }

    public function productionVersions(): HasMany
    {
        return $this->hasMany(MfgProductionVersion::class, 'mfg_item_id');
    }

    public function isRawMaterial(): bool
    {
        return $this->item_type === 'raw_material';
    }

    public function isFinishedGood(): bool
    {
        return $this->item_type === 'finished_good';
    }

    public function isSubassembly(): bool
    {
        return $this->item_type === 'subassembly';
    }

    public function isPhantom(): bool
    {
        return $this->item_type === 'phantom';
    }
}
