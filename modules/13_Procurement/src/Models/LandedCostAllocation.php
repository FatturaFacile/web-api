<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The share of a landed cost given to one goods receipt line, with the basis (value, weight or quantity) it was
 * computed on.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $landed_cost_id
 * @property string $purchase_receipt_id
 * @property string $purchase_receipt_line_id
 * @property string|null $stock_item_id
 * @property string $basis_value
 * @property string $allocated_amount
 */
final class LandedCostAllocation extends BaseEnterpriseModel
{
    protected $table = 'procurement_landed_cost_allocations';

    protected function casts(): array
    {
        return [
            'basis_value' => 'decimal:4',
            'allocated_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PurchaseReceiptLine, $this>
     */
    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceiptLine::class, 'purchase_receipt_line_id');
    }
}
