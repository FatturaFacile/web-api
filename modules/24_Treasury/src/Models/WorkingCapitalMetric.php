<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class WorkingCapitalMetric extends BaseEnterpriseModel
{
    protected $table = 'working_capital_metrics';

    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'accounts_receivable_total' => 'decimal:2',
            'accounts_payable_total' => 'decimal:2',
            'inventory_valuation_total' => 'decimal:2',
            'trailing_12m_revenue' => 'decimal:2',
            'trailing_12m_cogs' => 'decimal:2',
            'dso_days' => 'decimal:2',
            'dpo_days' => 'decimal:2',
            'dio_days' => 'decimal:2',
            'cash_conversion_cycle_days' => 'decimal:2',
            'current_ratio' => 'decimal:3',
            'quick_ratio' => 'decimal:3',
        ];
    }
}
