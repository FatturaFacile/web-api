<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A consolidation group: the tenant that owns it is the parent entity, whose users manage the group. The member
 * entities (other tenants of the same installation) are listed in `members`. Amounts of a run are in `group_currency`.
 * The four `*_code` columns name the synthetic group accounts a run may use (translation reserve, consolidation
 * difference, equity-method result and reserve); they exist only in the consolidation, never in a ledger.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $group_currency
 * @property string $translation_reserve_code
 * @property string $consolidation_difference_code
 * @property string $equity_method_result_code
 * @property string $equity_method_reserve_code
 * @property string $elimination_difference_policy
 * @property string $goodwill_method
 * @property int|null $goodwill_amortisation_years
 * @property bool $require_account_mapping
 * @property bool $deferred_tax_on_unrealised_profit
 * @property string|null $unrealised_profit_tax_rate
 * @property string $fair_value_first_year_rule
 * @property string $goodwill_translation
 * @property string $negative_goodwill_treatment
 * @property array<string, string>|null $synthetic_accounts
 */
final class ConsolidationGroup extends BaseEnterpriseModel
{
    protected $table = 'gl_consolidation_groups';

    /**
     * Roles of the synthetic accounts that exist only in the consolidation, with the code each has unless the group
     * overrides it in `synthetic_accounts`. The type of the account is fixed by the role, in the calculator.
     *
     * @var array<string, string>
     */
    public const array SyntheticDefaults = [
        'goodwill' => 'CONS-GOODWILL',
        'goodwill_accumulated' => 'CONS-GW-ACC',
        'goodwill_amortisation' => 'CONS-GW-AMORT',
        'goodwill_impairment' => 'CONS-GW-IMP',
        'goodwill_prior' => 'CONS-GW-PRIOR',
        'negative_goodwill' => 'CONS-NEG-GW',
        'preacquisition_reserves' => 'CONS-PRE-ACQ',
        'fair_value_reserve' => 'CONS-FV-RES',
        'deferred_tax' => 'CONS-DTL',
        'intercompany_difference' => 'CONS-IC-DIFF',
        'intercompany_pl_difference' => 'CONS-IC-PL-DIFF',
        'prior_unrealised_profit' => 'CONS-PRIOR-UNREAL',
        'deferred_tax_asset' => 'CONS-DTA',
        'deferred_tax_income' => 'CONS-DEFTAX-PL',
        'fair_value_depreciation' => 'CONS-FV-DEPR',
        'fair_value_prior' => 'CONS-FV-PRIOR',
        'consolidation_provision' => 'CONS-PROV',
        'provision_release_income' => 'CONS-PROV-REL',
        'provision_release_prior' => 'CONS-PROV-PRIOR',
        'bargain_purchase_gain' => 'CONS-BARGAIN',
        'bargain_purchase_prior' => 'CONS-BARGAIN-PRIOR',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'require_account_mapping' => 'boolean',
            'deferred_tax_on_unrealised_profit' => 'boolean',
            'goodwill_amortisation_years' => 'integer',
            'synthetic_accounts' => 'array',
        ];
    }

    /**
     * The code of a synthetic account of the group: the one the group set for the role, or the default.
     */
    public function syntheticCode(string $role): string
    {
        $custom = $this->synthetic_accounts[$role] ?? null;

        return is_string($custom) && $custom !== '' ? $custom : self::SyntheticDefaults[$role];
    }

    /**
     * @return HasMany<ConsolidationMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(ConsolidationMember::class, 'group_id')->orderByDesc('is_parent')->orderBy('created_at');
    }

    /**
     * @return HasMany<IntercompanyRelation, $this>
     */
    public function relations(): HasMany
    {
        return $this->hasMany(IntercompanyRelation::class, 'group_id');
    }

    /**
     * @return HasMany<ConsolidationRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(ConsolidationRun::class, 'group_id');
    }
}
