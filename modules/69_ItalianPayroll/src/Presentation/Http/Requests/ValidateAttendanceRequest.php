<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ValidateAttendanceRequest extends FormRequest
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
            'fringe_benefits' => ['nullable', 'array'],
            'fringe_benefits.*.employee_id' => ['required', 'uuid'],
            'fringe_benefits.*.amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}
