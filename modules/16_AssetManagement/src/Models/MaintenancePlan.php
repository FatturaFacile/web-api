<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eloquent model of the maintenance plan records of the asset management module.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $asset_id
 * @property string|null $asset_class_id
 * @property string|null $plan_code
 * @property string|null $name
 * @property string|null $maintenance_type
 * @property int|null $frequency_days
 * @property string|null $meter_interval_units
 * @property string|null $estimated_duration_hours
 * @property string|null $estimated_cost
 * @property Carbon|null $last_executed_date
 * @property Carbon|null $next_due_date
 * @property bool $is_active
 * @property array<string, mixed>|null $checklist
 * @property array<string, mixed>|null $translations
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property string|null $trigger_type
 * @property string|null $meter_code
 * @property string|null $last_meter_reading
 * @property string|null $next_due_meter
 * @property int|null $lead_time_days
 */
class MaintenancePlan extends BaseEnterpriseModel
{
    protected $table = 'eam_maintenance_plans';

    protected function casts(): array
    {
        return [
            'frequency_days' => 'integer',
            'meter_interval_units' => 'decimal:2',
            'estimated_duration_hours' => 'decimal:2',
            'estimated_cost' => 'decimal:2',
            'last_executed_date' => 'date',
            'next_due_date' => 'date',
            'is_active' => 'boolean',
            'checklist' => 'array',
            'translations' => 'array',
            'last_meter_reading' => 'decimal:2',
            'next_due_meter' => 'decimal:2',
            'lead_time_days' => 'integer',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function assetClass(): BelongsTo
    {
        return $this->belongsTo(AssetClass::class, 'asset_class_id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(MaintenanceWorkOrder::class, 'plan_id');
    }
}
