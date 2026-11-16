<?php

declare(strict_types=1);

namespace Modules\Logistics\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Logistics\Concerns\HasAuditUsers;

final class TransferDiscrepancy extends BaseEnterpriseModel
{
    use HasAuditUsers;

    protected $table = 'transfer_discrepancies';

    protected function casts(): array
    {
        return [
            'discrepancy_qty' => 'decimal:4',
            'shortage_qty' => 'decimal:4',
            'damage_qty' => 'decimal:4',
            'resolved_at' => 'datetime',
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
