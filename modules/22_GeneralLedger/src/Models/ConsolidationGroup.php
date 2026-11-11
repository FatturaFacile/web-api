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
 */
final class ConsolidationGroup extends BaseEnterpriseModel
{
    protected $table = 'gl_consolidation_groups';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
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
