<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eloquent model of the depreciation run records of the asset management module.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string|null $run_number
 * @property string|null $period_code
 * @property string|null $period_type
 * @property string|null $ledger_type
 * @property int|null $assets_processed_count
 * @property string|null $total_depreciation_amount
 * @property string|null $status
 * @property string|null $fiscal_period_id
 * @property string|null $executed_by_user_id
 * @property Carbon|null $executed_at
 * @property Carbon|null $posted_at
 * @property array<string, mixed>|null $summary_metrics
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
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
