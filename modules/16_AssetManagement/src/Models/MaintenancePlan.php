<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
