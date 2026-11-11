<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Two entities of a group that trade with or finance each other. `tolerance_amount` is the difference, in group
 * currency, below which the intercompany reconciliation treats two balances as matching (translation rounding).
 *
 * @property string $id
 * @property string $group_id
 * @property string $entity_a_tenant_id
 * @property string $entity_b_tenant_id
 * @property string $tolerance_amount
 */
final class IntercompanyRelation extends BaseEnterpriseModel
{
    protected $table = 'gl_intercompany_relations';

    protected function casts(): array
    {
        return ['tolerance_amount' => 'decimal:4'];
    }

    /**
     * @return BelongsTo<ConsolidationGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ConsolidationGroup::class, 'group_id');
    }

    /**
     * @return HasMany<IntercompanyAccountMapping, $this>
     */
    public function mappings(): HasMany
    {
        return $this->hasMany(IntercompanyAccountMapping::class, 'relation_id');
    }
}
