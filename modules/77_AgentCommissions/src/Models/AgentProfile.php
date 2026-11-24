<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Invoicing\Models\SalesRepresentative;

/**
 * Contract conditions of an agent: the commercial profile on top of a sales representative of the invoicing module.
 *
 * @property string $sales_representative_id
 * @property string $agent_type
 * @property string $fiscal_regime
 * @property string|null $enasarco_mandate
 * @property string|null $parent_agent_profile_id
 * @property string|null $parent_split_mode
 * @property string|null $parent_split_value
 * @property string $accrual_basis
 * @property string|null $default_rate
 * @property string $settlement_channel
 * @property bool $has_own_organization
 * @property bool $vat_applies
 * @property bool $firr_enabled
 * @property string|null $enasarco_registration_number
 * @property string|null $tax_code
 * @property string|null $vat_number
 * @property Carbon|null $contract_start
 * @property Carbon|null $contract_end
 * @property string|null $notes
 * @property bool $is_active
 * @property-read SalesRepresentative|null $representative
 * @property-read AgentProfile|null $parent
 */
final class AgentProfile extends BaseEnterpriseModel
{
    protected $table = 'agc_agent_profiles';

    protected function casts(): array
    {
        return [
            'parent_split_value' => 'decimal:4',
            'default_rate' => 'decimal:4',
            'has_own_organization' => 'boolean',
            'vat_applies' => 'boolean',
            'firr_enabled' => 'boolean',
            'is_active' => 'boolean',
            'contract_start' => 'date',
            'contract_end' => 'date',
        ];
    }

    /**
     * @return BelongsTo<SalesRepresentative, $this>
     */
    public function representative(): BelongsTo
    {
        return $this->belongsTo(SalesRepresentative::class, 'sales_representative_id');
    }

    /**
     * @return BelongsTo<AgentProfile, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_agent_profile_id');
    }

    /**
     * @return HasMany<CommissionRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(CommissionRule::class, 'agent_profile_id');
    }

    /**
     * @return HasMany<AgentProfile, $this>
     */
    public function subAgents(): HasMany
    {
        return $this->hasMany(self::class, 'parent_agent_profile_id');
    }
}
