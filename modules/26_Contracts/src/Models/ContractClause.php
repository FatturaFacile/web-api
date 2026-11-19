<?php

declare(strict_types=1);

namespace Modules\Contracts\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable contract clause of the library. The text lives in immutable versions; the current version is the latest one.
 *
 * @property string $code
 * @property string $title
 * @property string|null $category
 * @property string $status
 * @property int $current_version_no
 */
final class ContractClause extends BaseEnterpriseModel
{
    protected $table = 'contract_clauses';

    /** @var list<string> */
    public const array Statuses = ['draft', 'approved', 'retired'];

    protected function casts(): array
    {
        return ['current_version_no' => 'integer'];
    }

    /**
     * @return HasMany<ContractClauseVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ContractClauseVersion::class, 'clause_id')->orderBy('version_no');
    }

    /**
     * @return HasMany<ContractClauseFallback, $this>
     */
    public function fallbacks(): HasMany
    {
        return $this->hasMany(ContractClauseFallback::class, 'clause_id')->orderBy('rank');
    }
}
