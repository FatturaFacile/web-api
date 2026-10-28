<?php

declare(strict_types=1);

namespace App\Http\Requests\Appointments;

use Illuminate\Foundation\Http\FormRequest;

class CalendarQueryRequest extends FormRequest
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
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'technician_id' => ['nullable', 'uuid'],
            'contact_id' => ['nullable', 'uuid'],
            'status_id' => ['nullable', 'uuid'],
        ];
    }
}
