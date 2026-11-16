<?php

declare(strict_types=1);

namespace Modules\CorporateGroup\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ConsolidationRun extends BaseEnterpriseModel
{
    protected $table = 'consolidation_runs';

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'company_ids' => 'array',
            'exchange_rates' => 'array',
            'summary' => 'array',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ConsolidationLine::class, 'run_id')->orderBy('account_code');
    }

    public function eliminations(): HasMany
    {
        return $this->hasMany(ConsolidationElimination::class, 'run_id');
    }
}
