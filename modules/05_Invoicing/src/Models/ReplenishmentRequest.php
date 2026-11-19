<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A shortage of a sales order line pegged to a purchase requisition or a production order (open until converted).
 */
/**
 * @property Carbon|null $needed_by
 */
final class ReplenishmentRequest extends BaseEnterpriseModel
{
    protected $table = 'replenishment_requests';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'needed_by' => 'date',
        ];
    }

    /**
     * @return BelongsTo<SalesOrderItem, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class, 'sales_order_item_id');
    }
}
