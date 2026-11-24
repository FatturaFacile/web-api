<?php

declare(strict_types=1);

namespace Modules\SwissVat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateSwissVatTaxCodeRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'rate_type' => ['sometimes', 'string', Rule::in(['standard', 'reduced', 'accommodation', 'zero_exempt', 'saldosteuersatz', 'bezugsteuer'])],
            'exemption_type' => ['sometimes', 'string', Rule::in(['none', 'exempt_with_deduction', 'exempt_without_deduction', 'out_of_scope'])],
            'rate_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'afc_box' => ['sometimes', 'string', 'max:10'],
            'is_bezugsteuer' => ['sometimes', 'boolean'],
            'is_input_tax_deductible' => ['sometimes', 'boolean'],
            'sss_sector_code' => ['nullable', 'string', 'max:30'],
            'effective_from' => ['sometimes', 'date'],
            'effective_to' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
