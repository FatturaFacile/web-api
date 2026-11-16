<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A partner program tier: partners reach it by closed revenue and get its discount and MDF accrual rate.
 */
final class PartnerTier extends BaseEnterpriseModel
{
    protected $table = 'crm_partner_tiers';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'min_annual_revenue' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'mdf_percent' => 'decimal:2',
        ];
    }
}
