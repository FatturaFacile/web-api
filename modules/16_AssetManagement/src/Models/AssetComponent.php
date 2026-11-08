<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model of the asset component records of the asset management module.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $parent_asset_id
 * @property string|null $component_asset_id
 * @property string|null $component_code
 * @property string|null $name
 * @property string|null $serial_number
 * @property string|null $cost_allocation
 * @property int|null $useful_life_months
 * @property string|null $condition
 * @property string|null $status
 * @property Carbon|null $installed_date
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
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
