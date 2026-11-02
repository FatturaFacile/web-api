<?php

declare(strict_types=1);

namespace App\Http\Requests;

class ClockOutRequest extends AttendanceMutationRequest
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
            ...parent::rules(),
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
