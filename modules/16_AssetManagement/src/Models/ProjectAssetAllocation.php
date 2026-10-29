<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Projects\Models\Project;

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
