<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CapitalizeAssetRequest extends FormRequest
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
            'capitalized_cost' => ['required', 'numeric', 'min:0.01'],
            'salvage_value' => ['nullable', 'numeric', 'min:0'],
            'capitalization_date' => ['nullable', 'date'],
            'in_service_date' => ['nullable', 'date'],
            'depreciation_method' => ['nullable', 'string', 'in:straight_line,declining_balance,double_declining_balance,units_of_production,custom'],
            'useful_life_years' => ['nullable', 'integer', 'min:1', 'max:100'],
            'auto_post_to_gl' => ['nullable', 'boolean'],
        ];
    }
}
