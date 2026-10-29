<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
