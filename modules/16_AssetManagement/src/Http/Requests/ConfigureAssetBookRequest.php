<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for configuring a parallel depreciation book (CH AFC / IT D.M. 1988).
 */
final class ConfigureAssetBookRequest extends FormRequest
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
            'fiscal_regime' => ['nullable', 'string', 'in:civil_linear,ch_afc_degressive,ch_afc_linear,ch_afc_immediate,it_dm_1988'],
            'fiscal_rate_id' => ['nullable', 'uuid', 'exists:eam_fiscal_depreciation_rates,id'],
            'depreciation_method' => ['nullable', 'string', 'in:straight_line,declining_balance,double_declining_balance,units_of_production,immediate_expensing'],
            'rate_percent' => ['nullable', 'numeric', 'decimal:0,4', 'gt:0', 'max:100'],
            'useful_life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'declining_balance_factor' => ['nullable', 'numeric', 'decimal:0,4', 'gt:0', 'max:10'],
            'pro_rata_convention' => ['nullable', 'string', 'in:full,monthly,daily,half_year'],
            'switch_to_straight_line' => ['nullable', 'boolean'],
            'immediate_expensing' => ['nullable', 'boolean'],
            'first_year_half_rate' => ['nullable', 'boolean'],
            'pro_memoria_value' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:1000000'],
        ];
    }
}
