<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A CPQ pricing rule: a discount that applies from a minimum quantity, optionally only to partners of a tier, within a validity window. The highest matching discount wins.
 */
final class PricingRule extends BaseEnterpriseModel
{
    public const array Types = ['volume_discount', 'partner_discount', 'promotion'];

    protected $table = 'crm_pricing_rules';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_quantity' => 'decimal:3',
            'discount_percent' => 'decimal:2',
            'priority' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
