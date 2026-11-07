<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class StoreFixedAssetRequest extends FormRequest
{
    use TenantScopedRules;

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
            'category_id' => ['required', 'uuid', $this->tenantExists('eam_asset_categories')],
            'asset_class_id' => ['nullable', 'uuid', $this->tenantExists('eam_asset_classes')],
            'location_id' => ['nullable', 'uuid', $this->tenantExists('eam_asset_locations')],
            'parent_asset_id' => ['nullable', 'uuid', $this->tenantExists('eam_fixed_assets')],
            'custodian_user_id' => ['nullable', 'uuid', $this->tenantMember()],
            'project_id' => ['nullable', 'uuid'],
            'acquisition_cost' => ['required', 'numeric', 'min:0'],
            'salvage_value' => ['nullable', 'numeric', 'min:0'],
            'acquisition_type' => ['nullable', 'string', 'in:purchase,inventory_conversion,cip_project,lease,donated,manual'],
            'acquisition_date' => ['nullable', 'date'],
            'depreciation_method' => ['nullable', 'string', 'in:straight_line,declining_balance,double_declining_balance,units_of_production,custom'],
            'useful_life_years' => ['nullable', 'integer', 'min:1', 'max:100'],
            'total_expected_units' => ['nullable', 'numeric', 'min:0'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'barcode_tag' => ['nullable', 'string', 'max:100'],
            'condition' => ['nullable', 'string', 'in:new,excellent,good,fair,poor,damaged,decommissioned'],
            'specifications' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
