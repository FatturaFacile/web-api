<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation of a new fixed asset.
 */
final class StoreFixedAssetRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Access is decided by the module.access middleware and the policies of the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'uuid', $this->tenantOwns('eam_asset_categories')],
            'asset_class_id' => ['nullable', 'uuid', $this->tenantOwns('eam_asset_classes')],
            'location_id' => ['nullable', 'uuid', $this->tenantOwns('eam_asset_locations')],
            'parent_asset_id' => ['nullable', 'uuid', $this->tenantOwns('eam_fixed_assets')],
            'custodian_user_id' => ['nullable', 'uuid', $this->tenantMember()],
            'project_id' => ['nullable', 'uuid', $this->tenantOwns('projects')],
            'cost_center_id' => ['nullable', 'uuid', $this->tenantExists('cost_centers')],
            'supplier_id' => ['nullable', 'uuid', $this->tenantOwns('contacts')],
            'asset_number' => ['nullable', 'string', 'max:60', Rule::unique('eam_fixed_assets', 'asset_number')->where('tenant_id', $this->tenantId())],
            'acquisition_cost' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'salvage_value' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'replacement_cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'acquisition_type' => ['nullable', 'string', 'in:purchase,inventory_conversion,cip_project,lease,donated,manual'],
            'acquisition_date' => ['nullable', 'date'],
            'depreciation_method' => ['nullable', 'string', 'in:straight_line,declining_balance,double_declining_balance,units_of_production'],
            'pro_rata_convention' => ['nullable', 'string', 'in:full,monthly,daily,half_year'],
            'useful_life_years' => ['nullable', 'integer', 'min:1', 'max:100'],
            'total_expected_units' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'barcode_tag' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'condition' => ['nullable', 'string', 'in:new,excellent,good,fair,poor,damaged'],
            'warranty_expiry_date' => ['nullable', 'date'],
            'warranty_provider' => ['nullable', 'string', 'max:150'],
            'insurance_provider' => ['nullable', 'string', 'max:150'],
            'insurance_policy_number' => ['nullable', 'string', 'max:100'],
            'insured_value' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'insurance_expiry_date' => ['nullable', 'date'],
            'specifications' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * The active tenant.
     */
    private function tenantId(): ?string
    {
        return app(TenantContext::class)->getTenantId();
    }
}
