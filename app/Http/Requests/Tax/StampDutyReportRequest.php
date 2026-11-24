<?php

declare(strict_types=1);

namespace App\Http\Requests\Tax;

use Illuminate\Foundation\Http\FormRequest;

class StampDutyReportRequest extends FormRequest
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
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'quarter' => ['required', 'integer', 'between:1,4'],
            'format' => ['nullable', 'string', 'in:json,csv'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['quarter.between' => (string) __('tax.errors.invalid_quarter')];
    }
}
