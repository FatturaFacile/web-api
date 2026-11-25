<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class SbcDdtInvoice extends BaseEnterpriseModel
{
    protected $table = 'sbc_ddt_invoices';

    protected function casts(): array
    {
        return [
            'grouped_ddt_numbers' => 'array',
            'is_drop_shipping' => 'boolean',
            'total_net' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'total_gross' => 'decimal:2',
            'issue_date' => 'date',
        ];
    }
}
