<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * Eloquent model of the fixed asset records of the asset management module.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string|null $category_id
 * @property string|null $asset_class_id
 * @property string|null $location_id
 * @property string|null $parent_asset_id
 * @property string|null $custodian_user_id
 * @property string|null $project_id
 * @property string|null $supplier_id
 * @property string|null $purchase_order_id
 * @property string|null $purchase_receipt_id
 * @property string|null $inventory_item_id
 * @property string|null $asset_number
 * @property string|null $barcode_tag
 * @property string|null $serial_number
 * @property string|null $name
 * @property string|null $description
 * @property string|null $status
 * @property string|null $condition
 * @property string|null $acquisition_type
 * @property Carbon|null $acquisition_date
 * @property Carbon|null $capitalization_date
 * @property Carbon|null $commissioning_date
 * @property Carbon|null $in_service_date
 * @property Carbon|null $warranty_expiry_date
 * @property Carbon|null $retirement_date
 * @property Carbon|null $disposal_date
 * @property string|null $currency
 * @property string|null $acquisition_cost
 * @property string|null $capitalized_cost
 * @property string|null $salvage_value
 * @property string|null $depreciable_base
 * @property string|null $accumulated_depreciation
 * @property string|null $impairment_loss_total
 * @property string|null $revaluation_surplus_total
 * @property string|null $carrying_amount
 * @property string|null $replacement_cost
 * @property string|null $depreciation_method
 * @property int|null $useful_life_years
 * @property int|null $useful_life_months
 * @property string|null $depreciation_rate_percent
 * @property string|null $total_expected_units
 * @property string|null $consumed_units
 * @property Carbon|null $last_depreciation_date
 * @property bool $is_depreciable
 * @property bool $is_fully_depreciated
 * @property string|null $workflow_instance_id
 * @property string|null $approval_status
 * @property bool $is_locked
 * @property array<string, mixed>|null $specifications
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property string|null $cost_center_id
 * @property string|null $purchase_invoice_id
 * @property string|null $purchase_invoice_line_id
 * @property string|null $purchase_order_item_id
 * @property string|null $capitalization_account_id
 * @property CarbonImmutable|null $depreciation_start_date
 * @property string|null $pro_rata_convention
 * @property string|null $declining_balance_factor
 * @property string|null $warranty_provider
 * @property Carbon|null $warranty_alerted_at
 * @property string|null $insurance_provider
 * @property string|null $insurance_policy_number
 * @property string|null $insured_value
 * @property Carbon|null $insurance_expiry_date
 * @property Carbon|null $insurance_alerted_at
 */
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
            'depreciation_start_date' => 'immutable_date',
            'declining_balance_factor' => 'decimal:4',
            'insured_value' => 'decimal:2',
            'insurance_expiry_date' => 'date',
            'warranty_alerted_at' => 'datetime',
            'insurance_alerted_at' => 'datetime',
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

    /**
     * @return BelongsTo<AssetCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function assetClass(): BelongsTo
    {
        return $this->belongsTo(AssetClass::class, 'asset_class_id');
    }

    /**
     * @return BelongsTo<AssetLocation, $this>
     */
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

    /**
     * Parallel books of the asset (statutory, tax, IFRS, management).
     *
     * @return HasMany<AssetBook, $this>
     */
    public function books(): HasMany
    {
        return $this->hasMany(AssetBook::class, 'asset_id');
    }

    /**
     * Meter readings of the asset (operating hours, kilometres, cycles).
     *
     * @return HasMany<AssetMeterReading, $this>
     */
    public function meterReadings(): HasMany
    {
        return $this->hasMany(AssetMeterReading::class, 'asset_id');
    }
}
