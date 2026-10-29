<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ValuateAssetRequest extends FormRequest
{
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
            'fair_value' => ['required', 'numeric', 'min:0'],
            'value_in_use' => ['nullable', 'numeric', 'min:0'],
            'valuation_date' => ['nullable', 'date'],
            'appraiser_name' => ['nullable', 'string', 'max:150'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'auto_post_to_gl' => ['nullable', 'boolean'],
        ];
    }
}
