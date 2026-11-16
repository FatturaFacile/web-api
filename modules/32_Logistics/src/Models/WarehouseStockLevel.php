<?php

declare(strict_types=1);

namespace Modules\Logistics\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\Logistics\Concerns\HasAuditUsers;

final class WarehouseStockLevel extends BaseEnterpriseModel
{
    use HasAuditUsers;

    protected $table = 'warehouse_stock_levels';

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:4',
        ];
    }
}
