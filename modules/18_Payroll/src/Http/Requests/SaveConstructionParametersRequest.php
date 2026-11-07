<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveConstructionParametersRequest extends FormRequest
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
            'agreement_code' => ['required', 'string', 'max:30'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'annual_hours' => ['required', 'numeric', 'between:1,3000'],
            'accounting_year_start_month' => ['required', 'integer', 'between:1,12'],
            'minimum_wages' => ['present', 'array'],
            'minimum_wages.*.*.monthly' => ['required', 'numeric', 'min:0'],
            'minimum_wages.*.*.hourly' => ['required', 'numeric', 'min:0'],
            'source' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }
}
