<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eloquent model of the asset location records of the asset management module.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $parent_id
 * @property string|null $warehouse_id
 * @property string|null $code
 * @property string|null $name
 * @property string|null $location_type
 * @property string|null $address
 * @property string|null $city
 * @property string|null $country
 * @property string|null $latitude
 * @property string|null $longitude
 * @property bool $is_active
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class AssetLocation extends BaseEnterpriseModel
{
    protected $table = 'eam_asset_locations';

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function parentLocation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function childLocations(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class, 'location_id');
    }
}
