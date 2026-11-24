<?php

declare(strict_types=1);

namespace Modules\SwissVat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreInputTaxAdjustmentRequest extends FormRequest
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
            'adjustment_type' => ['required', 'string', Rule::in(['autoconsumo', 'degrassazione', 'mixed_use_reduction', 'subsidy_reduction'])],
            'effective_date' => ['required', 'date'],
            'asset_category' => ['nullable', 'string', Rule::in(['movable', 'real_estate', 'service', 'general'])],
            'description' => ['required', 'string', 'max:255'],
            'reference_document' => ['nullable', 'string', 'max:100'],
            'original_cost_chf' => ['nullable', 'numeric', 'gt:0'],
            'original_vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'years_elapsed' => ['nullable', 'integer', 'min:0', 'max:30'],
            'private_or_exempt_ratio' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'adjustment_amount_chf' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
