<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RecordVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'technician_id' => ['nullable', 'uuid'],
            'service_appointment_id' => ['nullable', 'uuid'],
            'check_in_at' => ['nullable', 'date'],
            'check_out_at' => ['nullable', 'date'],
            'work_summary' => ['nullable', 'string'],
            'customer_feedback' => ['nullable', 'string'],
        ];
    }
}
