<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * One month of the rolling DSCR projection saved by a snapshot.
 *
 * @property string $id
 * @property string|null $tenant_id
 */
final class DscrMonthlyProjection extends BaseEnterpriseModel
{
    protected $table = 'fin_dscr_monthly_projections';

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'month' => 'date',
            'operating_inflows' => 'decimal:2',
            'operating_outflows' => 'decimal:2',
            'debt_service' => 'decimal:2',
            'tax_social' => 'decimal:2',
            'capex' => 'decimal:2',
            'free_cash_flow' => 'decimal:2',
            'closing_cash' => 'decimal:2',
            'cumulative_cash_with_credit' => 'decimal:2',
            'dscr_treasury' => 'decimal:2',
            'dscr_operating' => 'decimal:2',
        ];
    }
}
