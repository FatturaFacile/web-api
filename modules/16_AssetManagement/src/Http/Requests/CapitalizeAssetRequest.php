<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation of the capitalization of an asset.
 */
final class CapitalizeAssetRequest extends FormRequest
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
            'capitalized_cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'salvage_value' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'capitalization_date' => ['nullable', 'date'],
            'in_service_date' => ['nullable', 'date', 'after_or_equal:capitalization_date'],
            'depreciation_start_date' => ['nullable', 'date'],
            'depreciation_method' => ['nullable', 'string', 'in:straight_line,declining_balance,double_declining_balance,units_of_production'],
            'pro_rata_convention' => ['nullable', 'string', 'in:full,monthly,daily,half_year'],
            'useful_life_years' => ['nullable', 'integer', 'min:1', 'max:100'],
            'auto_post_to_gl' => ['nullable', 'boolean'],
        ];
    }
}
