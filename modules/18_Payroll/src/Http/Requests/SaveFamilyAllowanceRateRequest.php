<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveFamilyAllowanceRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The minimums are the federal ones of the desktop validator (CHF 215 and 268 from 2025).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'canton' => ['required', Rule::in(PayrollRules::Cantons)],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'child_allowance' => ['required', 'numeric', 'min:215'],
            'education_allowance' => ['required', 'numeric', 'min:268'],
        ];
    }
}
