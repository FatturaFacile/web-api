<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Projects\Models\Project;

/**
 * Eloquent model of the project asset allocation records of the asset management module.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $asset_id
 * @property string|null $project_id
 * @property Carbon|null $allocated_from
 * @property Carbon|null $allocated_to
 * @property string|null $hourly_rate
 * @property string|null $daily_rate
 * @property string|null $utilized_hours
 * @property string|null $utilized_days
 * @property string|null $attributed_cost
 * @property string|null $status
 * @property string|null $allocated_by_user_id
 * @property Carbon|null $released_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class ProjectAssetAllocation extends BaseEnterpriseModel
{
    protected $table = 'eam_project_asset_allocations';

    protected function casts(): array
    {
        return [
            'allocated_from' => 'date',
            'allocated_to' => 'date',
            'hourly_rate' => 'decimal:2',
            'daily_rate' => 'decimal:2',
            'utilized_hours' => 'decimal:2',
            'utilized_days' => 'decimal:2',
            'attributed_cost' => 'decimal:2',
            'released_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
