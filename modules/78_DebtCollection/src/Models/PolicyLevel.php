<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * One dunning level (Mahnstufe 0-3) of a policy.
 *
 * @property string $policy_id
 * @property int $level
 * @property int $min_days_overdue
 * @property int $wait_days
 * @property int $deadline_days
 * @property string $fee_amount
 * @property bool $charge_interest
 * @property list<string>|null $channels
 * @property bool $legal_warning
 * @property bool $is_active
 */
final class PolicyLevel extends BaseEnterpriseModel
{
    protected $table = 'dc_policy_levels';

    protected function casts(): array
    {
        return ['min_days_overdue' => 'integer', 'wait_days' => 'integer', 'deadline_days' => 'integer', 'fee_amount' => 'decimal:2', 'charge_interest' => 'boolean', 'channels' => 'array', 'legal_warning' => 'boolean', 'is_active' => 'boolean', 'level' => 'integer'];
    }
}
