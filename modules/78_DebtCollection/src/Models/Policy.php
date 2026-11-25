<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dunning policy: the levels, thresholds and interest basis of one jurisdiction, optionally for one currency or customer group.
 *
 * @property string $name
 * @property string $jurisdiction
 * @property string|null $currency
 * @property string|null $customer_id
 * @property bool $is_default
 * @property bool $is_active
 * @property string $min_overdue_amount
 * @property string $interest_basis
 * @property string|null $contractual_rate_percent
 */
final class Policy extends BaseEnterpriseModel
{
    protected $table = 'dc_policies';

    protected function casts(): array
    {
        return ['min_overdue_amount' => 'decimal:2', 'contractual_rate_percent' => 'decimal:4', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    /**
     * @return HasMany<PolicyLevel, $this>
     */
    public function levels(): HasMany
    {
        return $this->hasMany(PolicyLevel::class, 'policy_id')->orderBy('level');
    }
}
