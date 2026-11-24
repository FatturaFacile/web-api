<?php

declare(strict_types=1);

namespace Modules\Logistics\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\Logistics\Concerns\HasAuditUsers;

final class ConsignmentConsumptionDeclarationLine extends BaseEnterpriseModel
{
    use HasAuditUsers;

    protected $table = 'consignment_consumption_declaration_lines';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'tax_rate' => 'decimal:2',
        ];
    }
}
