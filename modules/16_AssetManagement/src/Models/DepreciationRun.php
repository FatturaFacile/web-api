<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepreciationRun extends BaseEnterpriseModel
{
    protected $table = 'eam_depreciation_runs';

    protected function casts(): array
    {
        return [
            'assets_processed_count' => 'integer',
            'total_depreciation_amount' => 'decimal:2',
            'executed_at' => 'datetime',
            'posted_at' => 'datetime',
            'summary_metrics' => 'array',
        ];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(DepreciationSchedule::class, 'run_id');
    }
}
