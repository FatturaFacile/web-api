<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\SalesBoundedContext\Domain\Enums\CommissionAccrualBasis;

final class SbcCommissionStatement extends BaseEnterpriseModel
{
    protected $table = 'sbc_commission_statements';

    protected function casts(): array
    {
        return [
            'accrual_basis' => CommissionAccrualBasis::class,
            'commissionable_base' => 'decimal:2',
            'tier_rules' => 'array',
            'split_override_pct' => 'decimal:2',
            'gross_commission_amount' => 'decimal:2',
            'enasarco_company_amount' => 'decimal:2',
            'enasarco_agent_withholding' => 'decimal:2',
            'firr_accrual_amount' => 'decimal:2',
            'net_payable_amount' => 'decimal:2',
        ];
    }
}
