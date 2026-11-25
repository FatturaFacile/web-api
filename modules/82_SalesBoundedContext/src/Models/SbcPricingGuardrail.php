<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class SbcPricingGuardrail extends BaseEnterpriseModel
{
    protected $table = 'sbc_pricing_guardrails';

    protected function casts(): array
    {
        return [
            'base_unit_price' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'cascading_discounts' => 'array',
            'tier_breaks' => 'array',
            'promo_discount_pct' => 'decimal:2',
            'resolved_net_price' => 'decimal:4',
            'calculated_margin_pct' => 'decimal:2',
            'min_margin_pct' => 'decimal:2',
            'guardrail_breached' => 'boolean',
            'breach_audit_log' => 'array',
        ];
    }
}
