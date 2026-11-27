<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 */
final class SbcFrameworkOrder extends BaseEnterpriseModel
{
    protected $table = 'sbc_framework_orders';

    protected function casts(): array
    {
        return [
            'ceiling_amount' => 'decimal:2',
            'released_amount' => 'decimal:2',
            'remaining_ceiling' => 'decimal:2',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'atp_qty' => 'decimal:3',
            'ctp_qty' => 'decimal:3',
            'requested_qty' => 'decimal:3',
            'reserved_stock_qty' => 'decimal:3',
            'shipped_qty' => 'decimal:3',
            'backorder_qty' => 'decimal:3',
            'total_amount' => 'decimal:2',
            'lines' => 'array',
        ];
    }
}
