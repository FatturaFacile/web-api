<?php

declare(strict_types=1);

namespace Modules\Contracts\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A clause placed in a template at a position, optionally left out when assembling.
 *
 * @property string $template_id
 * @property string $clause_id
 * @property int $position
 * @property bool $is_optional
 */
final class ContractTemplateClause extends BaseEnterpriseModel
{
    protected $table = 'contract_template_clauses';

    protected function casts(): array
    {
        return ['position' => 'integer', 'is_optional' => 'boolean'];
    }

    /**
     * @return BelongsTo<ContractClause, $this>
     */
    public function clause(): BelongsTo
    {
        return $this->belongsTo(ContractClause::class, 'clause_id');
    }
}
