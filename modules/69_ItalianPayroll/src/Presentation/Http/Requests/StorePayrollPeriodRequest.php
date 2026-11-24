<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StorePayrollPeriodRequest extends FormRequest
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
            'legal_entity_id' => ['nullable', 'uuid'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'include_tredicesima' => ['nullable', 'boolean'],
            'include_quattordicesima' => ['nullable', 'boolean'],
        ];
    }
}
