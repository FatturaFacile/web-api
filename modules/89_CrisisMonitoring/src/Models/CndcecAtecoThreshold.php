<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * CNDCEC early-warning thresholds of one ATECO section (global reference data, percent values).
 *
 * @property string $id
 * @property string|null $tenant_id
 */
final class CndcecAtecoThreshold extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'fin_cndcec_ateco_thresholds';

    protected function casts(): array
    {
        return [
            'financial_charges_revenue_max' => 'decimal:2',
            'equity_total_debt_min' => 'decimal:2',
            'liquidity_ratio_min' => 'decimal:2',
            'cash_flow_assets_min' => 'decimal:2',
            'tax_social_debt_assets_max' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
