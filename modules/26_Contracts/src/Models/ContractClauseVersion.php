<?php

declare(strict_types=1);

namespace Modules\Contracts\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable version of the text of a clause.
 *
 * @property string $clause_id
 * @property int $version_no
 * @property string $body
 * @property string|null $change_note
 * @property string|null $created_by
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
