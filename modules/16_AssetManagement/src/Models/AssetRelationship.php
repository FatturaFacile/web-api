<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AssetRelationship extends BaseEnterpriseModel
{
    protected $table = 'eam_asset_relationships';

    protected function casts(): array
    {
        return [
            'is_critical_dependency' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function sourceAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'source_asset_id');
    }

    public function targetAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'target_asset_id');
    }
}
