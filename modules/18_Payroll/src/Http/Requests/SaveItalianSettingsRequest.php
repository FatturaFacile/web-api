<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveItalianSettingsRequest extends FormRequest
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
            'inps_company_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'inps_office_code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'inail_code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'employee_inps_rate_percent' => ['sometimes', 'numeric', 'between:0,100'],
            'employer_inps_rate_percent' => ['sometimes', 'numeric', 'between:0,100'],
        ];
    }
}
