<?php

declare(strict_types=1);

namespace Modules\Contracts\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A contract template: ordered clauses of the library plus the declared variables ({key, label, required, default}).
 */
final class ContractTemplate extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Statuses = ['draft', 'active', 'archived'];

    protected $table = 'contract_templates';

    protected function casts(): array
    {
        return ['variables' => 'array'];
    }

    /**
     * @return HasMany<ContractTemplateClause, $this>
     */
    public function templateClauses(): HasMany
    {
        return $this->hasMany(ContractTemplateClause::class, 'template_id')->orderBy('position');
    }
}
