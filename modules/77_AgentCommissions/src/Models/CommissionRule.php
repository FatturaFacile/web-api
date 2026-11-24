<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A commission rule, matched by product category, customer and zone, with a priority and optional progressive tiers.
 *
 * @property string|null $agent_profile_id
 * @property string $name
 * @property string|null $product_category_id
 * @property string|null $customer_id
 * @property string|null $zone_id
 * @property string $rate_type
 * @property string $rate
 * @property string|null $fixed_amount
 * @property string|null $accrual_basis
 * @property int $priority
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property bool $is_active
 * @property-read Collection<int, CommissionRuleTier> $tiers
 * @property-read Zone|null $zone
 */
final class CommissionRule extends BaseEnterpriseModel
{
    protected $table = 'agc_commission_rules';

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'fixed_amount' => 'decimal:2',
            'priority' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CommissionRuleTier, $this>
     */
    public function tiers(): HasMany
    {
        return $this->hasMany(CommissionRuleTier::class, 'rule_id')->orderBy('threshold_from');
    }

    /**
     * @return BelongsTo<Zone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }

    /**
     * @return BelongsTo<AgentProfile, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class, 'agent_profile_id');
    }
}
