<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a goods receipt: received, accepted and rejected quantities, lot and serials, the quality inspection
 * outcome and the order unit cost the stock was valued at.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $purchase_receipt_id
 * @property string $purchase_order_line_id
 * @property string $quantity_received
 * @property string $quantity_accepted
 * @property string $quantity_rejected
 * @property string $quantity_released
 * @property string $quantity_returned
 * @property string|null $unit_cost
 * @property string $inspection_status
 * @property string|null $lot_number
 * @property string|null $serial_number
 * @property list<string>|null $serial_numbers
 * @property string|null $warehouse_location_id
 * @property string|null $warehouse_bin_id
 * @property string $landed_cost_amount
 * @property string|null $rejection_reason
 * @property string|null $inspection_notes
 * @property PurchaseReceipt|null $receipt
 * @property PurchaseOrderLine|null $orderLine
 */
final class PurchaseReceiptLine extends BaseEnterpriseModel
{
    protected $table = 'purchase_receipt_lines';

    protected function casts(): array
    {
        return [
            'quantity_received' => 'decimal:4',
            'quantity_accepted' => 'decimal:4',
            'quantity_rejected' => 'decimal:4',
            'quantity_released' => 'decimal:4',
            'quantity_returned' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'landed_cost_amount' => 'decimal:2',
            'serial_numbers' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PurchaseReceipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    /**
     * @return BelongsTo<PurchaseOrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }
}
