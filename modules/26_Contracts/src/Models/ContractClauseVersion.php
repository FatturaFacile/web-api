<?php

declare(strict_types=1);

namespace Modules\Contracts\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable version of the text of a clause.
 */
final class ContractClauseVersion extends BaseEnterpriseModel
{
    protected $table = 'contract_clause_versions';

    protected function casts(): array
    {
        return ['version_no' => 'integer'];
    }

    /**
     * @return BelongsTo<ContractClause, $this>
     */
    public function clause(): BelongsTo
    {
        return $this->belongsTo(ContractClause::class, 'clause_id');
    }
}
