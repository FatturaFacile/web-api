<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\GeneralLedger\Enums\IntercompanyMappingType;

/**
 * Pairs an account of entity A with an account of entity B of an intercompany relation. The accounts are identified by
 * id in the chart of accounts of their own entity (a different tenant than the owner of the mapping).
 *
 * @property string $id
 * @property string $relation_id
 * @property IntercompanyMappingType $mapping_type
 * @property string $account_a_id
 * @property string|null $account_b_id
 */
final class IntercompanyAccountMapping extends BaseEnterpriseModel
{
    protected $table = 'gl_intercompany_account_mappings';

    protected function casts(): array
    {
        return ['mapping_type' => IntercompanyMappingType::class];
    }

    /**
     * @return BelongsTo<IntercompanyRelation, $this>
     */
    public function relation(): BelongsTo
    {
        return $this->belongsTo(IntercompanyRelation::class, 'relation_id');
    }
}
