<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eloquent model of the asset class records of the asset management module.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $code
 * @property string|null $name
 * @property string|null $criticality
 * @property int|null $default_maintenance_interval_days
 * @property bool $requires_custodian
 * @property bool $requires_calibration
 * @property bool $is_active
 * @property array<string, mixed>|null $translations
 * @property array<string, mixed>|null $technical_schema
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
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
