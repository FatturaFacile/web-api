<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InventoryAuditEntry extends BaseEnterpriseModel
{
    protected $table = 'inventory_audit_entries';

    protected function casts(): array
    {
        return [
            'quantity_change' => 'decimal:4',
            'valuation_impact' => 'decimal:2',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'movement_id');
    }
}
