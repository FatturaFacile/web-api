<?php

declare(strict_types=1);

namespace Modules\SwissVat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CalculateSwissVatReturnRequest extends FormRequest
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
            'fiscal_year' => ['required', 'integer', 'between:2018,2100'],
            'period_type' => ['nullable', 'string', Rule::in(['quarterly', 'semiannual', 'monthly'])],
            'period_number' => ['required', 'integer', 'between:1,12'],
            'accounting_method' => ['nullable', 'string', Rule::in(['effective', 'saldosteuersatz'])],
            'consideration_basis' => ['nullable', 'string', Rule::in(['agreed', 'received'])],
            'sales_lines' => ['nullable', 'array'],
            'purchase_lines' => ['nullable', 'array'],
            'other_flows' => ['nullable', 'array'],
            'reductions_box_235' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
