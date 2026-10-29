<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ThreeWayMatchLog extends BaseEnterpriseModel
{
    protected $table = 'three_way_match_logs';

    protected function casts(): array
    {
        return [
            'po_total_amount' => 'decimal:2',
            'grn_total_amount' => 'decimal:2',
            'invoice_total_amount' => 'decimal:2',
            'variance_amount' => 'decimal:2',
            'variance_percentage' => 'decimal:2',
            'discrepancy_details' => 'array',
            'evaluated_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }
}
