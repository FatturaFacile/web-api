<?php

declare(strict_types=1);

namespace Modules\Logistics\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\Logistics\Concerns\HasAuditUsers;

final class ConsignmentConsumptionLine extends BaseEnterpriseModel
{
    use HasAuditUsers;

    protected $table = 'consignment_consumption_lines';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'tax_rate' => 'decimal:2',
        ];
    }
}
