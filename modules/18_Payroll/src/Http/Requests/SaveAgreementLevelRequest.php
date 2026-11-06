<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveAgreementLevelRequest extends FormRequest
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
            'level' => ['required', 'string', 'max:30'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'monthly_minimum' => ['required', 'numeric', 'min:0'],
        ];
    }
}
