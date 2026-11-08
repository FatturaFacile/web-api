<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation of an asset category, its depreciation presets, fiscal parameters (configured by the tenant, none shipped) and ledger accounts.
 */
final class StoreAssetCategoryRequest extends FormRequest
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
            'code' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:60', Rule::unique('eam_asset_categories', 'code')->where('tenant_id', $this->tenantId())->ignore($this->route('id'))],
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:150'],
            'parent_id' => ['nullable', 'uuid', $this->tenantOwns('eam_asset_categories')],
            'asset_type' => ['nullable', 'string', 'in:tangible,intangible,financial,real_estate,right_of_use'],
            'depreciation_method' => ['nullable', 'string', 'in:straight_line,declining_balance,double_declining_balance,units_of_production'],
            'useful_life_years' => ['nullable', 'integer', 'min:1', 'max:100'],
            'statutory_rate_percent' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'salvage_value_percent' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'pro_rata_convention' => ['nullable', 'string', 'in:full,monthly,daily,half_year'],
            'declining_balance_factor' => ['nullable', 'numeric', 'decimal:0,4', 'gt:0', 'max:10'],
            'fiscal_depreciation_method' => ['nullable', 'string', 'in:straight_line,declining_balance,double_declining_balance,units_of_production'],
            'fiscal_rate_percent' => ['nullable', 'numeric', 'decimal:0,4', 'gt:0', 'max:100'],
            'fiscal_useful_life_years' => ['nullable', 'integer', 'min:1', 'max:100'],
            'fiscal_pro_rata_convention' => ['nullable', 'string', 'in:full,monthly,daily,half_year'],
            'rate_reference' => ['nullable', 'string', 'max:500'],
            'gl_asset_account_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'gl_accumulated_depreciation_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'gl_depreciation_expense_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'gl_impairment_account_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'gl_revaluation_reserve_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'gl_disposal_gain_loss_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'gl_disposal_loss_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'gl_disposal_proceeds_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'gl_clearing_account_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'is_depreciable' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
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
