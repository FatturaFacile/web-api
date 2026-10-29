<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AssetComponent extends BaseEnterpriseModel
{
    protected $table = 'eam_asset_components';

    protected function casts(): array
    {
        return [
            'cost_allocation' => 'decimal:2',
            'useful_life_months' => 'integer',
            'installed_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function parentAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'parent_asset_id');
    }

    public function componentAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'component_asset_id');
    }
}
