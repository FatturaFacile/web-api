<?php

declare(strict_types=1);

namespace Modules\SwissVat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreSwissVatTaxCodeRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'rate_type' => ['required', 'string', Rule::in(['standard', 'reduced', 'accommodation', 'zero_exempt', 'saldosteuersatz', 'bezugsteuer'])],
            'exemption_type' => ['nullable', 'string', Rule::in(['none', 'exempt_with_deduction', 'exempt_without_deduction', 'out_of_scope'])],
            'rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'afc_box' => ['required', 'string', 'max:10'],
            'is_bezugsteuer' => ['nullable', 'boolean'],
            'is_input_tax_deductible' => ['nullable', 'boolean'],
            'sss_sector_code' => ['nullable', 'string', 'max:30'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
