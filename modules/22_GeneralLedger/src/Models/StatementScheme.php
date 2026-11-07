<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reclassification layout written by the users of a company.
 */
final class StatementScheme extends BaseEnterpriseModel
{
    protected $table = 'gl_statement_schemes';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<StatementSchemeRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(StatementSchemeRow::class, 'scheme_id')->orderBy('sort_order');
    }
}
