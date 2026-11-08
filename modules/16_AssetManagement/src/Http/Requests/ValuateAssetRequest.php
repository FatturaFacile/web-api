<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation of a revaluation or impairment test.
 */
final class ValuateAssetRequest extends FormRequest
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
            'valuation_type' => ['required', 'string', 'in:revaluation,impairment'],
            'fair_value' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'value_in_use' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'valuation_date' => ['nullable', 'date'],
            'appraiser_name' => ['nullable', 'string', 'max:150'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'auto_post_to_gl' => ['nullable', 'boolean'],
        ];
    }
}
