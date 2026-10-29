<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockReorderRule extends BaseEnterpriseModel
{
    protected $table = 'stock_reorder_rules';

    protected function casts(): array
    {
        return [
            'min_stock_level' => 'decimal:4',
            'max_stock_level' => 'decimal:4',
            'reorder_point' => 'decimal:4',
            'economic_order_quantity' => 'decimal:4',
            'lead_time_days' => 'integer',
            'auto_trigger_requisition' => 'boolean',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function preferredSupplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'preferred_supplier_id');
    }
}
