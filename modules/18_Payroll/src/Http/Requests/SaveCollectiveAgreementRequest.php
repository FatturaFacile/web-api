<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveCollectiveAgreementRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:255'],
            'weekly_hours' => ['sometimes', 'numeric', 'between:0,80'],
            'installments' => ['sometimes', 'integer', 'between:12,14'],
            'fourteenth_month' => ['sometimes', 'integer', 'between:1,12'],
            'employer_inps_rate_percent' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
