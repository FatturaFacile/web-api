<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

class FixedAsset extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'eam_fixed_assets';

    protected function casts(): array
    {
        return [
            'acquisition_date' => 'date',
            'capitalization_date' => 'date',
            'commissioning_date' => 'date',
            'in_service_date' => 'date',
            'warranty_expiry_date' => 'date',
            'retirement_date' => 'date',
            'disposal_date' => 'date',
            'last_depreciation_date' => 'date',
            'acquisition_cost' => 'decimal:2',
            'capitalized_cost' => 'decimal:2',
            'salvage_value' => 'decimal:2',
            'depreciable_base' => 'decimal:2',
            'accumulated_depreciation' => 'decimal:2',
            'impairment_loss_total' => 'decimal:2',
            'revaluation_surplus_total' => 'decimal:2',
            'carrying_amount' => 'decimal:2',
            'replacement_cost' => 'decimal:2',
            'useful_life_years' => 'integer',
            'useful_life_months' => 'integer',
            'depreciation_rate_percent' => 'decimal:2',
            'total_expected_units' => 'decimal:2',
            'consumed_units' => 'decimal:2',
            'is_depreciable' => 'boolean',
            'is_fully_depreciated' => 'boolean',
            'is_locked' => 'boolean',
            'specifications' => 'array',
            'metadata' => 'array',
        ];
    }

    public function lockByWorkflow(WorkflowInstance $instance): void
    {
        $this->update([
            'workflow_instance_id' => $instance->id,
            'approval_status' => 'pending_approval',
            'is_locked' => true,
        ]);
    }

    public function isLockedByWorkflow(): bool
    {
        return (bool) $this->is_locked;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function assetClass(): BelongsTo
    {
        return $this->belongsTo(AssetClass::class, 'asset_class_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'location_id');
    }

    public function parentAsset(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_asset_id');
    }

    public function childAssets(): HasMany
    {
        return $this->hasMany(self::class, 'parent_asset_id');
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_user_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(AssetComponent::class, 'parent_asset_id');
    }

    public function custodians(): HasMany
    {
        return $this->hasMany(AssetCustodian::class, 'asset_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AssetDocument::class, 'asset_id');
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(AssetRelationship::class, 'source_asset_id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(AssetTransfer::class, 'asset_id');
    }

    public function depreciationSchedules(): HasMany
    {
        return $this->hasMany(DepreciationSchedule::class, 'asset_id');
    }

    public function valuations(): HasMany
    {
        return $this->hasMany(AssetValuation::class, 'asset_id');
    }

    public function disposals(): HasMany
    {
        return $this->hasMany(AssetDisposal::class, 'asset_id');
    }

    public function maintenancePlans(): HasMany
    {
        return $this->hasMany(MaintenancePlan::class, 'asset_id');
    }

    public function maintenanceWorkOrders(): HasMany
    {
        return $this->hasMany(MaintenanceWorkOrder::class, 'asset_id');
    }

    public function projectAllocations(): HasMany
    {
        return $this->hasMany(ProjectAssetAllocation::class, 'asset_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class, 'asset_id');
    }
}
