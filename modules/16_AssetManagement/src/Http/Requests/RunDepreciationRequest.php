<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RunDepreciationRequest extends FormRequest
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
            'period_code' => ['required', 'string', 'max:20'],
            'period_type' => ['nullable', 'string', 'in:monthly,yearly'],
            'ledger_type' => ['nullable', 'string', 'in:statutory,ifrs,tax,management'],
            'single_asset_id' => ['nullable', 'uuid', 'exists:eam_fixed_assets,id'],
            'units_produced_by_asset_id' => ['nullable', 'array'],
            'auto_post_to_gl' => ['nullable', 'boolean'],
        ];
    }
}
