<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A returned quantity of a purchase order line, taken from a goods receipt line.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $purchase_return_id
 * @property string $purchase_order_line_id
 * @property string|null $purchase_receipt_line_id
 * @property string $quantity_returned
 * @property string $unit_price
 * @property string $line_total
 * @property string|null $reason
 */
final class PurchaseReturnLine extends BaseEnterpriseModel
{
    protected $table = 'purchase_return_lines';

    protected function casts(): array
    {
        return [
            'quantity_returned' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'line_total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PurchaseReturn, $this>
     */
    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id');
    }

    /**
     * @return BelongsTo<PurchaseOrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }
}
