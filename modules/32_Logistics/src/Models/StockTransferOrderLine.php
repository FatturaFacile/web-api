<?php

declare(strict_types=1);

namespace Modules\Logistics\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Logistics\Concerns\HasAuditUsers;

final class StockTransferOrderLine extends BaseEnterpriseModel
{
    use HasAuditUsers;

    protected $table = 'stock_transfer_order_lines';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'shipped_qty' => 'decimal:4',
            'received_qty' => 'decimal:4',
            'discrepancy_qty' => 'decimal:4',
            'damaged_qty' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<StockTransferOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(StockTransferOrder::class, 'stock_transfer_order_id');
    }
}
