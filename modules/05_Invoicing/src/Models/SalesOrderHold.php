<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalesOrderHold extends BaseEnterpriseModel
{
    protected $table = 'sales_order_holds';

    protected function casts(): array
    {
        return [
            'placed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }
}
