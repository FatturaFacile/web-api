<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitLeaveRequestFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid'],
            'leave_type_id' => ['required', 'uuid'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'total_hours' => ['required', 'numeric', 'min:0.5'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'medical_certificate_ref' => ['nullable', 'string', 'max:120'],
        ];
    }
}
