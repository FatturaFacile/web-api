<?php

declare(strict_types=1);

namespace App\Http\Requests\Appointments;

use Illuminate\Foundation\Http\FormRequest;

class ConflictCheckRequest extends FormRequest
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
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['required', 'uuid'],
            'ignore_appointment_id' => ['nullable', 'uuid'],
        ];
    }
}
