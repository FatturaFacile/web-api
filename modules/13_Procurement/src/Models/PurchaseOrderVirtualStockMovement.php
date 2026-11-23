<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Virtual warehouse accounting of a dropshipped line: stock in from the supplier and out to the customer, never physical.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $purchase_order_dropship_id
 * @property string $purchase_order_line_id
 * @property string $direction
 * @property string $quantity_base
 * @property string|null $base_uom
 * @property string $unit_cost_base
 * @property string $valued_amount
 * @property Carbon $moved_at
 */
final class PurchaseOrderVirtualStockMovement extends BaseEnterpriseModel
{
    protected $table = 'purchase_order_virtual_stock_movements';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_base' => 'decimal:4',
            'unit_cost_base' => 'decimal:6',
            'valued_amount' => 'decimal:2',
            'moved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrderDropship, $this>
     */
    public function dropship(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderDropship::class, 'purchase_order_dropship_id');
    }

    /**
     * @return BelongsTo<PurchaseOrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }
}
