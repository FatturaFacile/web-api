<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model of the asset relationship records of the asset management module.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $source_asset_id
 * @property string|null $target_asset_id
 * @property string|null $relationship_type
 * @property bool $is_critical_dependency
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
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
