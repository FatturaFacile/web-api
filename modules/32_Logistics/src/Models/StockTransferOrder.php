<?php

declare(strict_types=1);

namespace Modules\Logistics\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Logistics\Concerns\HasAuditUsers;

final class StockTransferOrder extends BaseEnterpriseModel
{
    use HasAuditUsers;

    protected $table = 'stock_transfer_orders';

    protected function casts(): array
    {
        return [
            'is_inter_company' => 'boolean',
            'shipped_at' => 'datetime',
            'received_at' => 'datetime',
            'approved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<StockTransferOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferOrderLine::class, 'stock_transfer_order_id');
    }

    /**
     * @return HasMany<TransferDiscrepancy, $this>
     */
    public function discrepancies(): HasMany
    {
        return $this->hasMany(TransferDiscrepancy::class, 'stock_transfer_order_id');
    }
}
