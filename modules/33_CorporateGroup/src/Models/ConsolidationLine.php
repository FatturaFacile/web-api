<?php

declare(strict_types=1);

namespace Modules\CorporateGroup\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class ConsolidationLine extends BaseEnterpriseModel
{
    protected $table = 'consolidation_lines';

    protected function casts(): array
    {
        return [
            'aggregated_debit' => 'decimal:4',
            'aggregated_credit' => 'decimal:4',
            'elimination_debit' => 'decimal:4',
            'elimination_credit' => 'decimal:4',
            'consolidated_debit' => 'decimal:4',
            'consolidated_credit' => 'decimal:4',
            'company_breakdown' => 'array',
        ];
    }
}
