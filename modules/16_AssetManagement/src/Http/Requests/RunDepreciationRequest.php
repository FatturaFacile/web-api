<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation of a depreciation run or simulation.
 */
final class RunDepreciationRequest extends FormRequest
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
            'period_code' => ['required', 'string', 'max:20'],
            'period_type' => ['nullable', 'string', 'in:monthly,quarterly,yearly'],
            'ledger_type' => ['nullable', 'string', 'in:statutory,tax,ifrs,management,civil,fiscal'],
            'single_asset_id' => ['nullable', 'uuid', $this->tenantOwns('eam_fixed_assets')],
            'category_id' => ['nullable', 'uuid', $this->tenantOwns('eam_asset_categories')],
            'units_produced_by_asset_id' => ['nullable', 'array', 'max:5000'],
            'units_produced_by_asset_id.*' => ['numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'auto_post_to_gl' => ['nullable', 'boolean'],
        ];
    }
}
