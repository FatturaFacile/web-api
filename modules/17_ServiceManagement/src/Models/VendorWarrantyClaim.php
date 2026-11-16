<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Procurement\Models\Supplier;

/**
 * Claim-back towards the original supplier for a defective part replaced under warranty.
 *
 * @property string $id
 * @property string $rma_order_id
 * @property string|null $rma_line_id
 * @property string $original_supplier_id
 * @property string|null $defective_part_serial
 * @property string|null $defective_product_id
 * @property string|null $supplier_rma_reference
 * @property string $status
 * @property string $claimed_amount
 * @property string $currency
 * @property string|null $credit_note_reference
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property string|null $notes
 */
final class VendorWarrantyClaim extends BaseEnterpriseModel
{
    protected $table = 'esm_vendor_warranty_claims';

    protected function casts(): array
    {
        return [
            'claimed_amount' => 'decimal:2',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DepotRepairRmaOrder, $this>
     */
    public function rmaOrder(): BelongsTo
    {
        return $this->belongsTo(DepotRepairRmaOrder::class, 'rma_order_id');
    }

    /**
     * @return BelongsTo<RmaLine, $this>
     */
    public function rmaLine(): BelongsTo
    {
        return $this->belongsTo(RmaLine::class, 'rma_line_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'original_supplier_id');
    }
}
