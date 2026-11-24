<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A progressive tier of a commission rule over the yearly turnover of the rule.
 *
 * @property string $rule_id
 * @property string $threshold_from
 * @property string|null $threshold_to
 * @property string $rate
 */
final class CommissionRuleTier extends BaseEnterpriseModel
{
    protected $table = 'agc_commission_rule_tiers';

    protected function casts(): array
    {
        return [
            'threshold_from' => 'decimal:2',
            'threshold_to' => 'decimal:2',
            'rate' => 'decimal:4',
        ];
    }
}
