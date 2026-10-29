<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AssetClass extends BaseEnterpriseModel
{
    protected $table = 'eam_asset_classes';

    protected function casts(): array
    {
        return [
            'default_maintenance_interval_days' => 'integer',
            'requires_custodian' => 'boolean',
            'requires_calibration' => 'boolean',
            'is_active' => 'boolean',
            'translations' => 'array',
            'technical_schema' => 'array',
        ];
    }

    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class, 'asset_class_id');
    }

    public function maintenancePlans(): HasMany
    {
        return $this->hasMany(MaintenancePlan::class, 'asset_class_id');
    }
}
