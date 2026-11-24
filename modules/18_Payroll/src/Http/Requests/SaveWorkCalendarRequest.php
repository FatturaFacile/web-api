<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveWorkCalendarRequest extends FormRequest
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
            'months' => ['required', 'array', 'min:1', 'max:24'],
            'months.*.year' => ['required', 'integer', 'between:2000,2100'],
            'months.*.month' => ['required', 'integer', 'between:1,12'],
            'months.*.planned_hours' => ['required', 'numeric', 'between:0,400'],
        ];
    }
}
